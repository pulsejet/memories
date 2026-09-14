// Package api serves go-vod over HTTP, including HLS playlists.
package api

import (
	"fmt"
	"math"
	"net/http"
	"sort"
	"time"

	"github.com/grafov/m3u8"
	"github.com/pulsejet/memories/go-vod/core"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
)

func MasterPlaylist(renditions []core.Rendition, frameRate int, query string) (string, error) {
	sorted := append([]core.Rendition(nil), renditions...)
	sort.Slice(sorted, func(i, j int) bool {
		return sorted[i].Order < sorted[j].Order ||
			(sorted[i].Order == sorted[j].Order && sorted[i].Bitrate < sorted[j].Bitrate)
	})

	m := m3u8.NewMasterPlaylist()
	for _, r := range sorted {
		m.Append(r.Quality+".m3u8"+query, nil, m3u8.VariantParams{
			Bandwidth:  uint32(r.Bitrate),
			Resolution: fmt.Sprintf("%dx%d", r.Width, r.Height),
			FrameRate:  float64(frameRate),
		})
	}
	return m.Encode().String(), nil
}

// VariantPlaylist is the entry point for serving a rendition's playlist.
func VariantPlaylist(m *core.Manager, quality string, chunkSize int, query string) (string, error) {
	if quality == core.QUALITY_DIRECT {
		if segs, ok := m.CopySegments(); ok {
			return CopyVariantPlaylist(quality, segs, query)
		}
	}
	return TranscodeVariantPlaylist(quality, m.Duration(), chunkSize, query)
}

// TranscodeVariantPlaylist renders the variant playlist for a re-encoded
// rendition: uniform chunkSize segments.
func TranscodeVariantPlaylist(quality string, total time.Duration, chunkSize int, query string) (string, error) {
	n := 0
	if total > 0 && chunkSize > 0 {
		n = int(math.Ceil(total.Seconds() / float64(chunkSize)))
	}

	p, err := m3u8.NewMediaPlaylist(uint(n), uint(n))
	if err != nil {
		return "", err
	}
	p.SetVersion(4)
	p.MediaType = m3u8.VOD
	p.TargetDuration = float64(chunkSize)

	remaining := total.Seconds()
	for i := 0; i < n; i++ {
		size := float64(chunkSize)
		if remaining < size {
			size = remaining
		}
		if err := p.Append(fmt.Sprintf("%s-%06d.ts%s", quality, i, query), size, "nodesc"); err != nil {
			return "", err
		}
		remaining -= float64(chunkSize)
	}
	p.Close()
	return p.Encode().String(), nil
}

// CopyVariantPlaylist renders the variant playlist for a stream-copy
// rendition from its keyframe-derived segments.
func CopyVariantPlaylist(quality string, segments []ffmpeg.Segment, query string) (string, error) {
	n := len(segments)
	p, err := m3u8.NewMediaPlaylist(uint(n), uint(n))
	if err != nil {
		return "", err
	}
	p.SetVersion(4)
	p.MediaType = m3u8.VOD

	longest := 0.0
	for _, s := range segments {
		longest = max(longest, s.Duration)
	}
	p.TargetDuration = math.Ceil(longest)

	for i, s := range segments {
		if err := p.Append(fmt.Sprintf("%s-%06d.ts%s", quality, i, query), s.Duration, "nodesc"); err != nil {
			return "", err
		}
	}
	p.Close()
	return p.Encode().String(), nil
}

func QueryString(r *http.Request) string {
	if q := r.URL.Query().Encode(); q != "" {
		return "?" + q
	}
	return ""
}

func WriteM3U8(w http.ResponseWriter, body string) {
	w.Header().Set("Content-Type", "application/x-mpegURL")
	w.Write([]byte(body))
}
