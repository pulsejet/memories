package main

import (
	"fmt"
	"math"
	"net/http"
	"sort"
)

type Manager struct {
	path  string
	id    string
	close chan string

	duration  float64
	numChunks int
	chunkSize float64

	streams map[string]*Stream
}

func NewManager(path string, id string, close chan string) *Manager {
	m := &Manager{path: path, id: id, close: close}
	m.streams = make(map[string]*Stream)
	m.chunkSize = 4

	m.duration = 300

	m.numChunks = int(math.Ceil(m.duration / m.chunkSize))

	m.streams["360p.m3u8"] = &Stream{m: m, quality: "360p", height: 360, width: 640, bitrate: 945000}
	m.streams["480p.m3u8"] = &Stream{m: m, quality: "480p", height: 480, width: 640, bitrate: 1365000}
	m.streams["720p.m3u8"] = &Stream{m: m, quality: "720p", height: 720, width: 1280, bitrate: 3045000}
	m.streams["1080p.m3u8"] = &Stream{m: m, quality: "1080p", height: 1080, width: 1920, bitrate: 6045000}
	m.streams["1440p.m3u8"] = &Stream{m: m, quality: "1440p", height: 1440, width: 2560, bitrate: 9045000}
	m.streams["2160p.m3u8"] = &Stream{m: m, quality: "2160p", height: 2160, width: 3840, bitrate: 14045000}
	return m
}

func (m *Manager) ServeHTTP(w http.ResponseWriter, r *http.Request, chunk string) error {
	if chunk == "index.m3u8" {
		return m.ServeIndex(w, r)
	}

	if stream, ok := m.streams[chunk]; ok {
		return stream.ServeList(w, r)
	}

	w.WriteHeader(http.StatusNotFound)
	return nil
}

func (m *Manager) ServeIndex(w http.ResponseWriter, r *http.Request) error {
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
