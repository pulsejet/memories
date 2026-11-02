package core

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

// stubFFmpeg runs body and counts invocations in the returned file.
func stubFFmpeg(t *testing.T, body string) (bin, runs string) {
	t.Helper()
	dir := t.TempDir()
	runs = filepath.Join(dir, "runs")
	bin = filepath.Join(dir, "ffmpeg")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\necho >> "+runs+"\n"+body+"\n"), 0755))
	return bin, runs
}

func countRuns(t *testing.T, runs string) int {
	data, _ := os.ReadFile(runs)
	return strings.Count(string(data), "\n")
}

func TestStreamFailureRestartsOnRetry(t *testing.T) {
	m := newCopyManager(t, hevcProbeJSON, "", false, nil)
	bin, runs := stubFFmpeg(t, "exit 1")
	m.c.FFmpeg = bin

	old := chunkWait
	chunkWait = 2 * time.Second
	defer func() { chunkWait = old }()

	for i := 1; i <= 3; i++ {
		w := httptest.NewRecorder()
		start := time.Now()
		require.True(t, m.ServeChunk(w, QUALITY_MAX, 5))
		require.Equal(t, http.StatusRequestTimeout, w.Code)
		require.Less(t, time.Since(start), chunkWait)

		// Each retry starts a fresh ffmpeg instead of reusing the dead one.
		require.Equal(t, i, countRuns(t, runs))
		require.Eventually(t, func() bool {
			s := m.streams[QUALITY_MAX]
			s.mutex.Lock()
			defer s.mutex.Unlock()
			return s.coder == nil
		}, time.Second, 10*time.Millisecond)
	}
}

func TestStreamStartFailure(t *testing.T) {
	m := newCopyManager(t, hevcProbeJSON, "", false, nil)
	m.c.FFmpeg = filepath.Join(t.TempDir(), "missing")

	old := chunkWait
	chunkWait = 2 * time.Second
	defer func() { chunkWait = old }()

	// Advancing the goal used to signal a nil Process with the mutex held.
	for _, id := range []int{0, 1, 4} {
		w := httptest.NewRecorder()
		require.True(t, m.ServeChunk(w, QUALITY_MAX, id))
		require.Equal(t, http.StatusRequestTimeout, w.Code)
	}

	s := m.streams[QUALITY_MAX]
	require.True(t, s.mutex.TryLock())
	defer s.mutex.Unlock()
	require.Nil(t, s.coder)
	require.Empty(t, s.chunks)
}

func TestServeFullVideoStartFailure(t *testing.T) {
	m := newCopyManager(t, hevcProbeJSON, "", false, nil)
	m.c.FFmpeg = filepath.Join(t.TempDir(), "missing")

	w := httptest.NewRecorder()
	m.ServeFullVideo(w, httptest.NewRequest("GET", "/", nil), QUALITY_MAX)
	require.Equal(t, http.StatusInternalServerError, w.Code)
}
