package core

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

func stubProbe(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	require.NoError(t, os.WriteFile(filepath.Join(dir, "out.json"), []byte(
		`{"streams":[{"codec_type":"video","codec_name":"h264","width":64,"height":64,"avg_frame_rate":"30/1","duration":"1","bit_rate":"100"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+filepath.Join(dir, "out.json")+"\n"), 0755))
	return bin
}

func TestManagerStreamsInitialized(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	m, err := NewManager(cfg, "input.mp4", "id", "", 1, make(chan IdleEvent, 1))
	require.NoError(t, err)
	defer m.Destroy()

	require.NotEmpty(t, m.streams)
	for _, s := range m.streams {
		require.NotNil(t, s.chunks)
		require.NotNil(t, s.stop)
	}
}

func TestRegistryStaleRemove(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	reg := NewRegistry(cfg, make(chan IdleEvent, 16))

	m1, err := reg.GetOrCreate("input.mp4", "s", "")
	require.NoError(t, err)

	reg.Remove("s", m1.generation)
	m1.Destroy()

	m2, err := reg.GetOrCreate("input.mp4", "s", "")
	require.NoError(t, err)
	defer m2.Destroy()
	require.NotSame(t, m1, m2)

	reg.Remove("s", m1.generation)

	got, err := reg.GetOrCreate("input.mp4", "s", "")
	require.NoError(t, err)
	require.Same(t, m2, got)
}
