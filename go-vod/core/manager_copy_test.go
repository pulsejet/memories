package core

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
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

const copyProbeJSON = `{"streams":[{"codec_name":"h264","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"10","bit_rate":"1000000"}],"format":{}}`

func newCopyManager(t *testing.T, probeJSON, keyframes string, keyFail bool) *Manager {
	t.Helper()
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubCopyProbe(t, probeJSON, keyframes, keyFail)

	m, err := NewManager(cfg, "input.mp4", "id", 1, make(chan IdleEvent, 1))
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m
}

func TestManagerCopySegments(t *testing.T) {
	m := newCopyManager(t, copyProbeJSON, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	segs, ok := m.CopySegments()
	require.True(t, ok)
	require.Equal(t, 3, len(segs))
	require.Equal(t, 0.0, segs[0].Start)
	require.Equal(t, 4.0, segs[0].Duration)
	require.Equal(t, 8.0, segs[2].Start)
	require.Equal(t, 2.0, segs[2].Duration)
	require.True(t, m.HasStream(QUALITY_DIRECT))
	require.False(t, m.HasStream(QUALITY_MAX))
}

func TestManagerCopyDisabledCodec(t *testing.T) {
	probe := `{"streams":[{"codec_name":"hevc","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"10","bit_rate":"1000000"}],"format":{}}`
	m := newCopyManager(t, probe, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	_, ok := m.CopySegments()
	require.False(t, ok)
	require.False(t, m.HasStream(QUALITY_DIRECT))
	require.True(t, m.HasStream(QUALITY_MAX))
}

func TestManagerCopyDisabledRotation(t *testing.T) {
	probe := `{"streams":[{"codec_name":"h264","width":720,"height":1280,"avg_frame_rate":"30/1","duration":"10","bit_rate":"1000000","side_data_list":[{"side_data_type":"Display Matrix","rotation":90}]}],"format":{}}`
	m := newCopyManager(t, probe, "0.000000,K__\n4.000000,K__\n8.000000,K__\n", false)

	_, ok := m.CopySegments()
	require.False(t, ok)
	require.False(t, m.HasStream(QUALITY_DIRECT))
}

func TestManagerCopyKeyframeFailure(t *testing.T) {
	// A failing keyframe probe never fails the manager; max re-encodes.
	m := newCopyManager(t, copyProbeJSON, "", true)

	_, ok := m.CopySegments()
	require.False(t, ok)
	require.False(t, m.HasStream(QUALITY_DIRECT))
	require.True(t, m.HasStream(QUALITY_MAX))
}
