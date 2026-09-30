package core

import (
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

func TestChunkSignal(t *testing.T) {
	c := NewChunk(3)
	select {
	case <-c.doneCh:
		t.Fatal("signaled before signal")
	default:
	}

	c.signal(true)
	require.True(t, c.done)
	select {
	case <-c.doneCh:
	default:
		t.Fatal("not woken on completion")
	}

	require.NotPanics(t, func() { c.signal(true) })

	abandoned := NewChunk(4)
	abandoned.signal(false)
	require.False(t, abandoned.done)
	select {
	case <-abandoned.doneCh:
	default:
		t.Fatal("not woken on abandon")
	}
}

func testStream(t *testing.T) (*Stream, string) {
	t.Helper()
	dir := t.TempDir()
	return &Stream{
		c:       &config.Config{},
		m:       &Manager{tempDir: dir, id: "t"},
		quality: "480p",
		chunks:  make(map[int]*Chunk),
	}, dir
}

func TestWaitForChunkDone(t *testing.T) {
	s, dir := testStream(t)
	require.NoError(t, os.WriteFile(filepath.Join(dir, "480p-000000.ts"), []byte("segment"), 0644))

	chunk := NewChunk(0)
	chunk.signal(true)
	s.chunks[0] = chunk

	w := httptest.NewRecorder()
	s.waitForChunk(w, chunk)
	require.Equal(t, http.StatusOK, w.Code)
	require.Equal(t, "segment", w.Body.String())
}

func TestWaitForChunkAbandoned(t *testing.T) {
	s, _ := testStream(t)
	oldWait := chunkWait
	chunkWait = time.Second
	defer func() { chunkWait = oldWait }()

	chunk := NewChunk(1)
	s.chunks[1] = chunk
	go func() {
		time.Sleep(10 * time.Millisecond)
		s.mutex.Lock()
		s.coder = &exec.Cmd{}
		s.mutex.Unlock()
		chunk.signal(false)
	}()

	w := httptest.NewRecorder()
	s.waitForChunk(w, chunk)
	require.Equal(t, http.StatusConflict, w.Code)
}

func TestWaitForChunkTimeout(t *testing.T) {
	s, _ := testStream(t)
	oldWait := chunkWait
	chunkWait = 20 * time.Millisecond
	defer func() { chunkWait = oldWait }()

	chunk := NewChunk(2)
	s.chunks[2] = chunk

	w := httptest.NewRecorder()
	s.waitForChunk(w, chunk)
	require.Equal(t, http.StatusRequestTimeout, w.Code)
}
