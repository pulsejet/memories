package core

import (
	"encoding/json"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"github.com/stretchr/testify/require"
)

func TestIsStoryboardLeaf(t *testing.T) {
	require.True(t, IsStoryboardLeaf("storyboard.vtt"))
	require.True(t, IsStoryboardLeaf("storyboard-0.jpg"))
	require.True(t, IsStoryboardLeaf("storyboard-12.jpg"))
	require.False(t, IsStoryboardLeaf("index.m3u8"))
	require.False(t, IsStoryboardLeaf("storyboard-.jpg"))
	require.False(t, IsStoryboardLeaf("storyboard-1.png"))
	require.False(t, IsStoryboardLeaf("storyboard-../x.jpg"))
	require.False(t, IsStoryboardLeaf("../keyframes.json"))
	require.False(t, IsStoryboardLeaf("storyboard-0.jpg.part"))
}

func testStoryboardManager(t *testing.T, ffmpeg string) *Manager {
	t.Helper()
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	t.Setenv("CACHE_DIR", t.TempDir())
	cfg.FFprobe = stubProbe(t)
	cfg.FFmpeg = ffmpeg

	m, err := NewManager(NewManagerArgs{
		C:             cfg,
		ManagerParams: ManagerParams{URL: "http://localhost/input.mp4", StreamID: "id", FileID: 7, Etag: "etag-01"},
		Generation:    1,
		Idle:          make(chan IdleEvent, 1),
	})
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m
}

func TestEnsureStoryboardCached(t *testing.T) {
	m := testStoryboardManager(t, "false")
	dir := FileCacheDir(m.c.CacheDir(), m.fileid)
	require.NoError(t, os.MkdirAll(dir, 0755))
	data, err := json.Marshal(storyboardFile{Etag: m.etag, Plan: ffmpeg.PlanStoryboard(12 * time.Second)})
	require.NoError(t, err)
	require.NoError(t, os.WriteFile(filepath.Join(dir, StoryboardPlanFile), data, 0644))

	got, err := m.EnsureStoryboard()
	require.NoError(t, err)
	require.Equal(t, dir, got)
}

func TestEnsureStoryboardEtagMismatchEvictsFile(t *testing.T) {
	m := testStoryboardManager(t, "false")
	dir := FileCacheDir(m.c.CacheDir(), m.fileid)
	require.NoError(t, os.MkdirAll(dir, 0755))
	// Stale plan plus an unrelated sibling artifact.
	data, err := json.Marshal(storyboardFile{Etag: "stale", Plan: ffmpeg.PlanStoryboard(12 * time.Second)})
	require.NoError(t, err)
	require.NoError(t, os.WriteFile(filepath.Join(dir, StoryboardPlanFile), data, 0644))
	require.NoError(t, os.WriteFile(filepath.Join(dir, KeyframeCacheFile), []byte("{}"), 0644))

	_, err = LoadStoryboardPlan(m.c.CacheDir(), m.fileid, m.etag)
	require.Error(t, err)
	_, statErr := os.Stat(dir)
	require.True(t, os.IsNotExist(statErr))
}

func TestServeStoryboardVTTBakesQuery(t *testing.T) {
	m := testStoryboardManager(t, "false")
	dir := FileCacheDir(m.c.CacheDir(), m.fileid)
	require.NoError(t, os.MkdirAll(dir, 0755))
	plan := ffmpeg.PlanStoryboard(12 * time.Second)
	data, err := json.Marshal(storyboardFile{Etag: m.etag, Plan: plan})
	require.NoError(t, err)
	require.NoError(t, os.WriteFile(filepath.Join(dir, StoryboardPlanFile), data, 0644))

	w := httptest.NewRecorder()
	m.ServeStoryboard(w, httptest.NewRequest("GET", "/x", nil), StoryboardVTTFile, "?token=abc")
	require.Equal(t, 200, w.Code)
	require.Equal(t, "text/vtt", w.Header().Get("Content-Type"))
	require.Contains(t, w.Body.String(), "storyboard-0.jpg?token=abc#xywh=0,0,160,90")
}

func TestEnsureStoryboardNoEtag(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	t.Setenv("CACHE_DIR", t.TempDir())
	cfg.FFprobe = stubProbe(t)

	m, err := NewManager(NewManagerArgs{
		C:             cfg,
		ManagerParams: ManagerParams{URL: "http://localhost/input.mp4", StreamID: "id"},
		Generation:    1,
		Idle:          make(chan IdleEvent, 1),
	})
	require.NoError(t, err)
	defer m.Destroy()

	_, err = m.EnsureStoryboard()
	require.Error(t, err)
}

func TestEnsureStoryboardBuildFailure(t *testing.T) {
	m := testStoryboardManager(t, "false")

	_, err := m.EnsureStoryboard()
	require.Error(t, err)
	// Failed builds leave no ready marker behind.
	_, statErr := os.Stat(filepath.Join(FileCacheDir(m.c.CacheDir(), m.fileid), StoryboardPlanFile))
	require.Error(t, statErr)
}

func TestServeStoryboardRejectsLeaf(t *testing.T) {
	m := testStoryboardManager(t, "false")

	w := httptest.NewRecorder()
	m.ServeStoryboard(w, httptest.NewRequest("GET", "/x", nil), "../keyframes.json", "")
	require.Equal(t, 404, w.Code)
}

func TestStoryboardBuildsSerializeAtOneSlot(t *testing.T) {
	old := storyboardSlots
	storyboardSlots = make(chan struct{}, 1)
	defer func() { storyboardSlots = old }()

	dir := t.TempDir()
	script := "#!/bin/sh\npat=\"\"\nfor a in \"$@\"; do pat=\"$a\"; done\nsleep 1\ntouch \"$(dirname \"$pat\")/storyboard-0.jpg.part\"\n"
	bin := filepath.Join(dir, "ffmpeg")
	require.NoError(t, os.WriteFile(bin, []byte(script), 0755))

	inputs := make([]StoryboardInput, 0, 2)
	for _, fileid := range []int64{7, 8} {
		cfg := config.Defaults("test")
		cfg.TempDir = t.TempDir()
		t.Setenv("CACHE_DIR", dir)
		cfg.FFprobe = stubProbe(t)
		cfg.FFmpeg = bin

		m, err := NewManager(NewManagerArgs{
			C:             cfg,
			ManagerParams: ManagerParams{URL: "http://localhost/input.mp4", StreamID: "id", FileID: fileid, Etag: "etag-01"},
			Generation:    1,
			Idle:          make(chan IdleEvent, 1),
		})
		require.NoError(t, err)
		defer m.Destroy()
		inputs = append(inputs, m.storyboardInput())
	}

	start := time.Now()
	errs := make(chan error, 2)
	for _, in := range inputs {
		go func() { _, err := sharedStoryboards.Ensure(in); errs <- err }()
	}
	for range inputs {
		require.NoError(t, <-errs)
	}
	require.GreaterOrEqual(t, time.Since(start), 1500*time.Millisecond)
}

func TestStoryboardSurvivesManagerDestroy(t *testing.T) {
	dir := t.TempDir()
	script := "#!/bin/sh\npat=\"\"\nfor a in \"$@\"; do pat=\"$a\"; done\nsleep 2\ntouch \"$(dirname \"$pat\")/storyboard-0.jpg.part\"\n"
	bin := filepath.Join(dir, "ffmpeg")
	require.NoError(t, os.WriteFile(bin, []byte(script), 0755))

	cacheDir := t.TempDir()
	t.Setenv("CACHE_DIR", cacheDir)
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)
	cfg.FFmpeg = bin

	m, err := NewManager(NewManagerArgs{
		C:             cfg,
		ManagerParams: ManagerParams{URL: "http://localhost/input.mp4", StreamID: "id", FileID: 7, Etag: "etag-01"},
		Generation:    1,
		Idle:          make(chan IdleEvent, 1),
	})
	require.NoError(t, err)
	in := m.storyboardInput()
	m.Destroy()

	got, err := sharedStoryboards.Ensure(in)
	require.NoError(t, err)
	require.Equal(t, FileCacheDir(cacheDir, 7), got)

	w := httptest.NewRecorder()
	sharedStoryboards.Serve(w, httptest.NewRequest("GET", "/x", nil), in, StoryboardVTTFile, "")
	require.Equal(t, 200, w.Code)
	require.Contains(t, w.Body.String(), "storyboard-0.jpg#xywh=")
}
