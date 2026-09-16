package core

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"github.com/stretchr/testify/require"
)

// stubCopyProbe fakes ffprobe with separate responses for the stream probe
// (probeJSON) and the keyframe pass (keyframes), selected on the skip_frame
// flag. With keyFail the keyframe pass errors instead.
func stubCopyProbe(t *testing.T, probeJSON, keyframes string, keyFail bool) string {
	t.Helper()
	dir := t.TempDir()
	require.NoError(t, os.WriteFile(filepath.Join(dir, "out.json"), []byte(probeJSON), 0644))
	require.NoError(t, os.WriteFile(filepath.Join(dir, "keys.txt"), []byte(keyframes), 0644))

	keyCmd := "cat " + filepath.Join(dir, "keys.txt") + "; exit 0"
	if keyFail {
		keyCmd = "echo boom >&2; exit 1"
	}
	script := "#!/bin/sh\nfor a in \"$@\"; do\n  if [ \"$a\" = \"packet=pts_time,flags\" ]; then\n    " +
		keyCmd + "\n  fi\ndone\ncat " + filepath.Join(dir, "out.json") + "\n"
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte(script), 0755))
	return bin
}

const copyProbeJSON = `{"streams":[{"codec_type":"video","codec_name":"h264","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"10","bit_rate":"1000000"}],"format":{}}`

func newCopyManager(t *testing.T, probeJSON, keyframes string, keyFail bool) *Manager {
	t.Helper()
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubCopyProbe(t, probeJSON, keyframes, keyFail)

	m, err := NewManager(cfg, "input.mp4", "id", 0, "", 1, make(chan IdleEvent, 1))
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m
}

func TestManagerCopySegments(t *testing.T) {
	m := newCopyManager(t, copyProbeJSON, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	// Lazy: creation advertises direct without extracting keyframes.
	require.True(t, m.IsCopyEligible())
	require.True(t, m.HasStream(QUALITY_DIRECT))
	require.False(t, m.HasStream(QUALITY_MAX))
	_, ok := m.CopySegments()
	require.False(t, ok)

	segs, ok := m.EnsureCopySegments()
	require.True(t, ok)
	require.Equal(t, 3, len(segs))
	require.Equal(t, 0.0, segs[0].Start)
	require.Equal(t, 4.0, segs[0].Duration)
	require.Equal(t, 8.0, segs[2].Start)
	require.Equal(t, 2.0, segs[2].Duration)
}

func TestManagerCopyDisabledCodec(t *testing.T) {
	probe := `{"streams":[{"codec_type":"video","codec_name":"hevc","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"10","bit_rate":"1000000"}],"format":{}}`
	m := newCopyManager(t, probe, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	_, ok := m.CopySegments()
	require.False(t, ok)
	require.False(t, m.HasStream(QUALITY_DIRECT))
	require.True(t, m.HasStream(QUALITY_MAX))
}

func TestManagerCopyDisabledRotation(t *testing.T) {
	probe := `{"streams":[{"codec_type":"video","codec_name":"h264","width":720,"height":1280,"avg_frame_rate":"30/1","duration":"10","bit_rate":"1000000","side_data_list":[{"side_data_type":"Display Matrix","rotation":90}]}],"format":{}}`
	m := newCopyManager(t, probe, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	_, ok := m.CopySegments()
	require.False(t, ok)
	require.False(t, m.HasStream(QUALITY_DIRECT))
}

func TestManagerCopyKeyframeFailure(t *testing.T) {
	// A failing keyframe probe never fails the manager; direct falls back
	// to re-encoding (max-style) while lower renditions keep playing.
	m := newCopyManager(t, copyProbeJSON, "", true)

	require.True(t, m.IsCopyEligible())
	require.True(t, m.HasStream(QUALITY_DIRECT))
	require.False(t, m.HasStream(QUALITY_MAX))

	_, ok := m.EnsureCopySegments()
	require.False(t, ok)
	_, ok = m.CopySegments()
	require.False(t, ok)
	// Second ensure fails fast without re-probing.
	_, ok = m.EnsureCopySegments()
	require.False(t, ok)

	// Without a grid direct re-encodes for HLS but still copies for MP4.
	direct := m.streams[QUALITY_DIRECT]
	require.False(t, direct.spec(0, true).Copy)
	require.True(t, direct.spec(0, false).Copy)
}

func TestManagerCopyGridSpec(t *testing.T) {
	m := newCopyManager(t, copyProbeJSON, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	// With a grid direct copies for both HLS and MP4.
	_, ok := m.EnsureCopySegments()
	require.True(t, ok)
	direct := m.streams[QUALITY_DIRECT]
	require.True(t, direct.spec(0, true).Copy)
	require.True(t, direct.spec(0, false).Copy)
}

func TestManagerDirectChunkFallsBack(t *testing.T) {
	m := newCopyManager(t, copyProbeJSON, "", true)

	old := chunkWait
	chunkWait = 50 * time.Millisecond
	defer func() { chunkWait = old }()

	// Empty grid serves (re-encode attempt) instead of 404.
	w := httptest.NewRecorder()
	require.True(t, m.ServeChunk(w, QUALITY_DIRECT, 0))
	require.Equal(t, http.StatusRequestTimeout, w.Code)
}

// newBlockingCopyManager fakes ffprobe with a keyframe pass that signals
// via dir/started on entry and waits for dir/release before answering.
func newBlockingCopyManager(t *testing.T) (*Manager, string) {
	t.Helper()
	dir := t.TempDir()
	require.NoError(t, os.WriteFile(filepath.Join(dir, "out.json"), []byte(copyProbeJSON), 0644))
	require.NoError(t, os.WriteFile(filepath.Join(dir, "keys.txt"), []byte("0.000000,K__\n4.000000,K__\n8.000000,K__\n"), 0644))
	script := "#!/bin/sh\nfor a in \"$@\"; do\n  if [ \"$a\" = \"packet=pts_time,flags\" ]; then\n" +
		"    touch " + filepath.Join(dir, "started") + "\n" +
		"    while [ ! -f " + filepath.Join(dir, "release") + " ]; do sleep 0.05; done\n" +
		"    cat " + filepath.Join(dir, "keys.txt") + "; exit 0\n" +
		"  fi\ndone\ncat " + filepath.Join(dir, "out.json") + "\n"
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte(script), 0755))

	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = bin
	m, err := NewManager(cfg, "input.mp4", "id", 0, "", 1, make(chan IdleEvent, 1))
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m, dir
}

func TestKeyframeProbeDoesNotBlockTranscodes(t *testing.T) {
	m, dir := newBlockingCopyManager(t)

	var transcode *Stream
	for _, s := range m.streams {
		if s.quality != QUALITY_DIRECT && s.quality != QUALITY_MAX {
			transcode = s
			break
		}
	}
	require.NotNil(t, transcode)

	type result struct {
		segs []ffmpeg.Segment
		ok   bool
	}
	ensureRes := make(chan result, 1)
	go func() {
		segs, ok := m.EnsureCopySegments()
		ensureRes <- result{segs, ok}
	}()

	// Wait until the probe is inside ffprobe.
	deadline := time.Now().Add(10 * time.Second)
	for {
		if _, err := os.Stat(filepath.Join(dir, "started")); err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("keyframe probe never started")
		}
		time.Sleep(20 * time.Millisecond)
	}

	// Everything 1080p.ts needs (CopySegments in transcode/spec) must
	// stay fast while direct.m3u8 sits in the probe.
	fast := make(chan bool, 1)
	go func() {
		_, ok := m.CopySegments()
		_ = transcode.spec(0, true)
		fast <- ok
	}()
	select {
	case ok := <-fast:
		require.False(t, ok) // no grid yet
	case <-time.After(5 * time.Second):
		t.Fatal("transcode path blocked behind keyframe probe")
	}

	require.NoError(t, os.WriteFile(filepath.Join(dir, "release"), []byte{}, 0644))
	select {
	case r := <-ensureRes:
		require.True(t, r.ok)
		require.Len(t, r.segs, 3)
	case <-time.After(10 * time.Second):
		t.Fatal("keyframe probe never finished")
	}
}

func TestDirectPendingFastWhileProbeBlocked(t *testing.T) {
	m, dir := newBlockingCopyManager(t)

	m.StartCopyProbeAsync()

	deadline := time.Now().Add(10 * time.Second)
	for {
		if _, err := os.Stat(filepath.Join(dir, "started")); err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("keyframe probe never started")
		}
		time.Sleep(20 * time.Millisecond)
	}

	fast := make(chan bool, 1)
	go func() {
		_, _ = m.TryCacheCopySegments()
		fast <- m.CopyProbed()
	}()
	select {
	case probed := <-fast:
		require.False(t, probed)
	case <-time.After(5 * time.Second):
		t.Fatal("direct pending check blocked behind keyframe probe")
	}

	require.NoError(t, os.WriteFile(filepath.Join(dir, "release"), []byte{}, 0644))
	deadline = time.Now().Add(10 * time.Second)
	for {
		if segs, ok := m.TryCacheCopySegments(); ok {
			require.Len(t, segs, 3)
			return
		}
		if segs, ok := m.CopySegments(); ok {
			require.Len(t, segs, 3)
			return
		}
		if time.Now().After(deadline) {
			t.Fatal("grid never warmed after probe release")
		}
		time.Sleep(20 * time.Millisecond)
	}
}
