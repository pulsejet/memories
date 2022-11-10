package main

import (
	"fmt"
	"net/http"
)

type Stream struct {
	c       *Config
	m       *Manager
	quality string
	height  int
	width   int
	bitrate int
}

func (s *Stream) ServeList(w http.ResponseWriter, r *http.Request) error {
	WriteM3U8ContentType(w)
	w.Write([]byte("#EXTM3U\n"))
	w.Write([]byte("#EXT-X-VERSION:4\n"))
	w.Write([]byte("#EXT-X-MEDIA-SEQUENCE:0\n"))
	w.Write([]byte("#EXT-X-PLAYLIST-TYPE:VOD\n"))
	w.Write([]byte(fmt.Sprintf("#EXT-X-TARGETDURATION:%.3f\n", s.c.chunkSize)))

	duration := s.m.probe.Duration.Seconds()
	i := 0
	for duration > 0 {
		size := s.c.chunkSize
		if duration < size {
			size = duration
		}

		w.Write([]byte(fmt.Sprintf("#EXTINF:%.3f, nodesc\n", size)))
		w.Write([]byte(fmt.Sprintf("%s-%06d.ts\n", s.quality, i)))

		duration -= s.c.chunkSize
		i++
	}

	w.Write([]byte("#EXT-X-ENDLIST\n"))

	return nil
}

// Bulk
func (s *Stream) ServeChunk(w http.ResponseWriter, r *http.Request, chunkId int) error {
	w.Write([]byte("chunk"))
	return nil
}
