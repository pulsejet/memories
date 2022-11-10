package main

import (
	"fmt"
	"net/http"
)

type Stream struct {
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
	w.Write([]byte(fmt.Sprintf("#EXT-X-TARGETDURATION:%.3f\n", s.m.chunkSize)))

	for i := 0; i < s.m.numChunks; i++ {
		w.Write([]byte(fmt.Sprintf("#EXTINF:%.3f, nodesc\n", s.m.chunkSize)))
		w.Write([]byte(fmt.Sprintf("%s-%06d.ts\n", s.quality, i)))
	}

	w.Write([]byte("#EXT-X-ENDLIST\n"))

	return nil
}
