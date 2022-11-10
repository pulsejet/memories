package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"net/http"
	"os/exec"
	"sort"
	"strconv"
	"strings"
	"time"
)

type Manager struct {
	c *Config

	path  string
	id    string
	close chan string

	probe     *ProbeVideoData
	numChunks int

	streams map[string]*Stream
}

type ProbeVideoData struct {
	Width    int
	Height   int
	Duration time.Duration
}

func NewManager(c *Config, path string, id string, close chan string) (*Manager, error) {
	m := &Manager{c: c, path: path, id: id, close: close}
	m.streams = make(map[string]*Stream)

	if err := m.ffprobe(); err != nil {
		return nil, err
	}

	m.numChunks = int(math.Ceil(m.probe.Duration.Seconds() / float64(c.chunkSize)))

	// Possible streams
	m.streams["360p"] = &Stream{c: c, m: m, quality: "360p", height: 360, width: 640, bitrate: 800000}
	m.streams["480p"] = &Stream{c: c, m: m, quality: "480p", height: 480, width: 640, bitrate: 1500000}
	m.streams["720p"] = &Stream{c: c, m: m, quality: "720p", height: 720, width: 1280, bitrate: 3000000}
	m.streams["1080p"] = &Stream{c: c, m: m, quality: "1080p", height: 1080, width: 1920, bitrate: 5000000}
	m.streams["1440p"] = &Stream{c: c, m: m, quality: "1440p", height: 1440, width: 2560, bitrate: 9000000}
	m.streams["2160p"] = &Stream{c: c, m: m, quality: "2160p", height: 2160, width: 3840, bitrate: 14000000}

	// Only keep streams that are smaller than the video
	for k, stream := range m.streams {
		if stream.height > m.probe.Height || stream.width > m.probe.Width {
			delete(m.streams, k)
		}
	}

	log.Println("New manager", m.id,
		"with streams:", len(m.streams),
		"duration:", m.probe.Duration,
		"resolution:", m.probe.Width, "x", m.probe.Height,
	)

	return m, nil
}

func (m *Manager) ServeHTTP(w http.ResponseWriter, r *http.Request, chunk string) error {
	// Master list
	if chunk == "index.m3u8" {
		return m.ServeIndex(w)
	}

	// Stream list
	m3u8Sfx := ".m3u8"
	if strings.HasSuffix(chunk, m3u8Sfx) {
		quality := strings.TrimSuffix(chunk, m3u8Sfx)
		if stream, ok := m.streams[quality]; ok {
			return stream.ServeList(w)
		}
	}

	// Stream chunk
	tsSfx := ".ts"
	if strings.HasSuffix(chunk, tsSfx) {
		parts := strings.Split(chunk, "-")
		if len(parts) != 2 {
			w.WriteHeader(http.StatusBadRequest)
			return nil
		}

		quality := parts[0]
		chunkIdStr := strings.TrimSuffix(parts[1], tsSfx)
		chunkId, err := strconv.Atoi(chunkIdStr)
		if err != nil {
			w.WriteHeader(http.StatusBadRequest)
			return nil
		}

		if stream, ok := m.streams[quality]; ok {
			return stream.ServeChunk(w, chunkId)
		}
	}

	w.WriteHeader(http.StatusNotFound)
	return nil
}

func (m *Manager) ServeIndex(w http.ResponseWriter) error {
	WriteM3U8ContentType(w)
	w.Write([]byte("#EXTM3U\n"))

	// get sorted streams by bitrate
	streams := make([]*Stream, 0)
	for _, stream := range m.streams {
		streams = append(streams, stream)
	}
	sort.Slice(streams, func(i, j int) bool {
		return streams[i].bitrate < streams[j].bitrate
	})

	// Write all streams
	for _, stream := range streams {
		s := fmt.Sprintf("#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,NAME=%s\n%s.m3u8\n", stream.bitrate, stream.width, stream.height, stream.quality, stream.quality)
		w.Write([]byte(s))
	}
	return nil
}

func WriteM3U8ContentType(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/x-mpegURL")
}

func (m *Manager) ffprobe() error {
	args := []string{
		// Hide debug information
		"-v", "error",

		// video
		"-show_entries", "format=duration",
		"-show_entries", "stream=duration,width,height",
		"-select_streams", "v", // Video stream only, we're not interested in audio

		"-of", "json",
		m.path,
	}

	ctx, _ := context.WithDeadline(context.TODO(), time.Now().Add(5*time.Second))
	cmd := exec.CommandContext(ctx, m.c.ffprobe, args...)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()
	if err != nil {
		log.Println(stderr.String())
		return err
	}

	out := struct {
		Streams []struct {
			Width    int    `json:"width"`
			Height   int    `json:"height"`
			Duration string `json:"duration"`
		} `json:"streams"`
		Format struct {
			Duration string `json:"duration"`
		} `json:"format"`
	}{}

	if err := json.Unmarshal(stdout.Bytes(), &out); err != nil {
		return err
	}

	var duration time.Duration
	if out.Streams[0].Duration != "" {
		duration, err = time.ParseDuration(out.Streams[0].Duration + "s")
		if err != nil {
			return err
		}
	}
	if out.Format.Duration != "" {
		duration, err = time.ParseDuration(out.Format.Duration + "s")
		if err != nil {
			return err
		}
	}

	m.probe = &ProbeVideoData{
		Width:    out.Streams[0].Width,
		Height:   out.Streams[0].Height,
		Duration: duration,
	}

	return nil
}
