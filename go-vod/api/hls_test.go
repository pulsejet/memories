package api

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/core"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"github.com/stretchr/testify/require"
)

func TestMasterPlaylist(t *testing.T) {
	got, err := MasterPlaylist([]core.Rendition{
		{Quality: "1080p", Width: 1920, Height: 1080, Bitrate: 1000},
		{Quality: "max", Width: 1920, Height: 1080, Bitrate: 5000000, Order: 1},
		{Quality: "480p", Width: 854, Height: 480, Bitrate: 400},
	}, 30, "?t=123")
	require.NoError(t, err)
	require.Less(t, strings.Index(got, "480p.m3u8"), strings.Index(got, "1080p.m3u8"))
	require.Less(t, strings.Index(got, "1080p.m3u8"), strings.Index(got, "max.m3u8"))
	require.Contains(t, got, "BANDWIDTH=400,RESOLUTION=854x480")
	require.Contains(t, got, "480p.m3u8?t=123")
}

func TestTranscodeVariantPlaylist(t *testing.T) {
	got, err := TranscodeVariantPlaylist("720p", 62*time.Second+500*time.Millisecond, 3, "?t=123")
	require.NoError(t, err)
	require.Equal(t, 21, strings.Count(got, "#EXTINF"))
	require.Contains(t, got, "#EXTINF:2.500,nodesc\n720p-000020.ts?t=123")
	require.Contains(t, got, "#EXT-X-TARGETDURATION:3")
	require.Contains(t, got, "#EXT-X-PLAYLIST-TYPE:VOD")
	require.True(t, strings.HasSuffix(strings.TrimSpace(got), "#EXT-X-ENDLIST"))
}

func TestTranscodeVariantPlaylistEmpty(t *testing.T) {
	got, err := TranscodeVariantPlaylist("720p", 0, 3, "")
	require.NoError(t, err)
	require.NotContains(t, got, "#EXTINF")
	require.Contains(t, got, "#EXT-X-ENDLIST")
}

func TestCopyVariantPlaylist(t *testing.T) {
	got, err := CopyVariantPlaylist("direct", []ffmpeg.Segment{
		{Start: 0, Duration: 6},
		{Start: 6, Duration: 8.5},
		{Start: 14.5, Duration: 2.25},
	}, "?t=123")
	require.NoError(t, err)
	require.Equal(t, 3, strings.Count(got, "#EXTINF"))
	require.Contains(t, got, "6")
	require.Contains(t, got, "8.5")
	require.Contains(t, got, "2.25")
	require.Contains(t, got, "#EXT-X-TARGETDURATION:9")
	require.Contains(t, got, "direct-000000.ts?t=123")
	require.Contains(t, got, "direct-000002.ts?t=123")
	require.True(t, strings.HasSuffix(strings.TrimSpace(got), "#EXT-X-ENDLIST"))
}

func stubVariantManager(t *testing.T, keyFail bool) *core.Manager {
	t.Helper()
	dir := t.TempDir()
	probeJSON := `{"streams":[{"codec_type":"video","codec_name":"h264","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"12","bit_rate":"1000000"}],"format":{}}`
	require.NoError(t, os.WriteFile(filepath.Join(dir, "out.json"), []byte(probeJSON), 0644))
	require.NoError(t, os.WriteFile(filepath.Join(dir, "keys.txt"), []byte("0.000000,K__\n4.000000,K__\n8.000000,K__\n"), 0644))
	keyCmd := "cat " + filepath.Join(dir, "keys.txt") + "; exit 0"
	if keyFail {
		keyCmd = "echo boom >&2; exit 1"
	}
	script := "#!/bin/sh\nfor a in \"$@\"; do\n  if [ \"$a\" = \"packet=pts_time,flags\" ]; then\n    " +
		keyCmd + "\n  fi\ndone\ncat " + filepath.Join(dir, "out.json") + "\n"
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte(script), 0755))

	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = bin
	m, err := core.NewManager(cfg, "input.mp4", "id", 0, "", 1, make(chan core.IdleEvent, 1))
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m
}

func TestVariantPlaylistLazyDirect(t *testing.T) {
	m := stubVariantManager(t, false)

	// Transcodes never touch keyframes, even with a failing keyframe pass.
	got, err := VariantPlaylist(m, "480p", 4, "")
	require.NoError(t, err)
	require.Contains(t, got, "480p-000000.ts")

	// Only direct.m3u8 extracts keyframes.
	got, err = VariantPlaylist(m, "direct", 4, "")
	require.NoError(t, err)
	require.Contains(t, got, "direct-000000.ts")
}

func TestVariantPlaylistDirectFallback(t *testing.T) {
	m := stubVariantManager(t, true)

	// Zero keyframes: direct serves max-style uniform segments under its
	// own name instead of failing.
	got, err := VariantPlaylist(m, "direct", 4, "")
	require.NoError(t, err)
	require.Contains(t, got, "direct-000000.ts")
	require.NotContains(t, got, "max-")
	require.Contains(t, got, "#EXT-X-TARGETDURATION:4")

	// Lower renditions still serve.
	_, err = VariantPlaylist(m, "480p", 4, "")
	require.NoError(t, err)
}
