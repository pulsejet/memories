package core

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"syscall"
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
			return s.exited
		}, time.Second, 10*time.Millisecond)
	}
}

// segmentFFmpeg writes and announces only segment 0, then exits cleanly.
const segmentFFmpeg = `while [ $# -gt 0 ]; do [ "$1" = -hls_segment_filename ] && p="$2"; shift; done
f=$(printf "$p" 0); echo data > "$f"; basename "$f"`

func TestStreamCleanExitWakesWaiters(t *testing.T) {
	m := newCopyManager(t, hevcProbeJSON, "", false, nil)
	bin, runs := stubFFmpeg(t, segmentFFmpeg)
	m.c.FFmpeg = bin

	old := chunkWait
	chunkWait = 2 * time.Second
	defer func() { chunkWait = old }()

	w := httptest.NewRecorder()
	require.True(t, m.ServeChunk(w, QUALITY_MAX, 0))
	require.Equal(t, http.StatusOK, w.Code)
	require.Equal(t, "data\n", w.Body.String())

	// Chunk 1 is never produced, e.g. a playlist entry past EOF. The exited
	// coder no longer counts as close: a retry restarts and fails fast.
	require.Eventually(t, func() bool {
		s := m.streams[QUALITY_MAX]
		s.mutex.Lock()
		defer s.mutex.Unlock()
		return s.exited
	}, time.Second, 10*time.Millisecond)
	for i := 2; i <= 3; i++ {
		w = httptest.NewRecorder()
		start := time.Now()
		require.True(t, m.ServeChunk(w, QUALITY_MAX, 1))
		require.Equal(t, http.StatusRequestTimeout, w.Code)
		require.Less(t, time.Since(start), chunkWait)
		require.Equal(t, i, countRuns(t, runs))
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

func TestStreamStopKillsCoder(t *testing.T) {
	m := newCopyManager(t, hevcProbeJSON, "", false, nil)
	bin, runs := stubFFmpeg(t, "exec sleep 30")
	m.c.FFmpeg = bin

	old := chunkWait
	chunkWait = 50 * time.Millisecond
	defer func() { chunkWait = old }()

	w := httptest.NewRecorder()
	require.True(t, m.ServeChunk(w, QUALITY_MAX, 0))
	require.Equal(t, http.StatusRequestTimeout, w.Code)

	s := m.streams[QUALITY_MAX]
	s.mutex.Lock()
	pid := s.coder.Process.Pid
	s.mutex.Unlock()

	// Destroy kills the transcode synchronously, not via the Run loop.
	m.Destroy()
	require.Eventually(t, func() bool {
		return syscall.Kill(pid, 0) == syscall.ESRCH
	}, 5*time.Second, 20*time.Millisecond)

	// A request racing teardown can't start an unsupervised ffmpeg.
	w = httptest.NewRecorder()
	require.True(t, m.ServeChunk(w, QUALITY_MAX, 5))
	require.Equal(t, http.StatusConflict, w.Code)
	require.Equal(t, 1, countRuns(t, runs))
}
