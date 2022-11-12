package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"hash/fnv"
	"log"
	"math"
	"net/http"
	"os"
	"os/exec"
	"sort"
	"strconv"
	"strings"
	"time"
)

type Manager struct {
	c *Config

	path     string
	tempDir  string
	id       string
	close    chan string
	inactive int

	probe     *ProbeVideoData
	numChunks int

	streams map[string]*Stream
}

type ProbeVideoData struct {
	Width     int
	Height    int
	Duration  time.Duration
	FrameRate int
	CodecName string
	BitRate   int
}

func NewManager(c *Config, path string, id string, close chan string) (*Manager, error) {
	m := &Manager{c: c, path: path, id: id, close: close}
	m.streams = make(map[string]*Stream)

	h := fnv.New32a()
	h.Write([]byte(path))
	ph := fmt.Sprint(h.Sum32())
	m.tempDir = fmt.Sprintf("%s/%s-%s", m.c.tempdir, id, ph)

	// Delete temp dir if exists
	os.RemoveAll(m.tempDir)
	os.MkdirAll(m.tempDir, 0755)

	if err := m.ffprobe(); err != nil {
		return nil, err
	}

	// heuristic
	if m.probe.CodecName != "h264" {
		m.probe.BitRate *= 2
	}

	m.numChunks = int(math.Ceil(m.probe.Duration.Seconds() / float64(c.chunkSize)))

	// Possible streams
	m.streams["360p"] = &Stream{c: c, m: m, quality: "360p", height: 360, width: 640, bitrate: 500000}
	m.streams["480p"] = &Stream{c: c, m: m, quality: "480p", height: 480, width: 640, bitrate: 1200000}
	m.streams["720p"] = &Stream{c: c, m: m, quality: "720p", height: 720, width: 1280, bitrate: 2200000}
	m.streams["1080p"] = &Stream{c: c, m: m, quality: "1080p", height: 1080, width: 1920, bitrate: 3600000}
	m.streams["1440p"] = &Stream{c: c, m: m, quality: "1440p", height: 1440, width: 2560, bitrate: 6000000}
	m.streams["2160p"] = &Stream{c: c, m: m, quality: "2160p", height: 2160, width: 3840, bitrate: 10000000}

	// Only keep streams that are smaller than the video
	for k, stream := range m.streams {
		// scale bitrate by frame rate with reference 30
		stream.bitrate = int(float64(stream.bitrate) * float64(m.probe.FrameRate) / 30.0)

		if stream.height > m.probe.Height || stream.width > m.probe.Width {
			delete(m.streams, k)
		}

		if stream.height == m.probe.Height || stream.width == m.probe.Width {
			if stream.bitrate > m.probe.BitRate || float64(stream.bitrate) > float64(m.probe.BitRate)*0.8 {
				// no point in "upscaling"
				// should have at least 20% difference
				delete(m.streams, k)
			}
		}
	}

	// Original stream
	m.streams["max"] = &Stream{
		c: c, m: m, quality: "max", height: m.probe.Height, width: m.probe.Width,
		bitrate: m.probe.BitRate,
	}

	// Start all streams
	for _, stream := range m.streams {
		go stream.Run()
	}

	log.Printf("%s: new manager for %s", m.id, m.path)

	// Check for inactivity
	go func() {
		t := time.NewTicker(5 * time.Second)
		defer t.Stop()
		for {
			<-t.C

			if m.inactive == -1 {
				t.Stop()
				return
			}

			m.inactive++

			// Check if any stream is active
			for _, stream := range m.streams {
				if stream.coder != nil {
					m.inactive = 0
					break
				}
			}

			// Nothing done for 5 minutes
			if m.inactive >= m.c.managerIdleTime/5 {
				t.Stop()
				m.Destroy()
				m.close <- m.id
				return
			}
		}
	}()

	return m, nil
}

// Destroys streams. DOES NOT emit on the close channel.
func (m *Manager) Destroy() {
	log.Printf("%s: destroying manager", m.id)
	m.inactive = -1

	for _, stream := range m.streams {
		stream.Stop()
	}

	// Delete temp dir
	os.RemoveAll(m.tempDir)
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
		s := fmt.Sprintf("#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,FRAME-RATE=%d\n%s.m3u8\n", stream.bitrate, stream.width, stream.height, m.probe.FrameRate, stream.quality)
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
		"-show_entries", "stream=duration,width,height,avg_frame_rate,codec_name,bit_rate",
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
			Width     int    `json:"width"`
			Height    int    `json:"height"`
			Duration  string `json:"duration"`
			FrameRate string `json:"avg_frame_rate"`
			CodecName string `json:"codec_name"`
			BitRate   string `json:"bit_rate"`
		} `json:"streams"`
		Format struct {
			Duration string `json:"duration"`
		} `json:"format"`
	}{}

	if err := json.Unmarshal(stdout.Bytes(), &out); err != nil {
		return err
	}

	if len(out.Streams) == 0 {
		return errors.New("no video streams found")
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

	// FrameRate is a fraction string
	frac := strings.Split(out.Streams[0].FrameRate, "/")
	if len(frac) != 2 {
		frac = []string{"30", "1"}
	}
	num, e1 := strconv.Atoi(frac[0])
	den, e2 := strconv.Atoi(frac[1])
	if e1 != nil || e2 != nil {
		num = 30
		den = 1
	}
	frameRate := float64(num) / float64(den)

	// BitRate is a string
	bitRate, err := strconv.Atoi(out.Streams[0].BitRate)
	if err != nil {
		bitRate = 5000000
	}

	m.probe = &ProbeVideoData{
		Width:     out.Streams[0].Width,
		Height:    out.Streams[0].Height,
		Duration:  duration,
		FrameRate: int(frameRate),
		CodecName: out.Streams[0].CodecName,
		BitRate:   bitRate,
	}

	return nil
}
