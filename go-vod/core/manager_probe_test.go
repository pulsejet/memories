package core

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

func TestManagerFFprobeFailure(t *testing.T) {
	bin := filepath.Join(t.TempDir(), "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\necho boom >&2\nexit 1\n"), 0755))

	m := &Manager{c: &config.Config{FFprobe: bin}, url: "http://localhost/input.mp4"}
	err := m.ffprobe()
	require.Error(t, err)
	require.Contains(t, err.Error(), "boom")
}
