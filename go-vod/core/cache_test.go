package core

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

func newCopyManagerWithEtag(t *testing.T, probeJSON, keyframes string, keyFail bool, cacheDir, etag string) *Manager {
	t.Helper()
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.CacheDir = cacheDir
	cfg.FFprobe = stubCopyProbe(t, probeJSON, keyframes, keyFail)

	m, err := NewManager(cfg, "input.mp4", "id", 7, etag, 1, make(chan IdleEvent, 1))
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m
}

func TestCacheFileDir(t *testing.T) {
	base := t.TempDir()
	d1 := FileCacheDir(base, 123)
	d2 := FileCacheDir(base, 123)
	require.Equal(t, d1, d2)
	require.NotEqual(t, d1, FileCacheDir(base, 124))
	require.Contains(t, d1, "123")

	rel, err := filepath.Rel(base, d1)
	require.NoError(t, err)
	require.Equal(t, 2, len(strings.Split(rel, string(os.PathSeparator))))
	require.Equal(t, KeyframeCacheFile, filepath.Base(KeyframeCachePath(base, 123)))

	require.Equal(t, "", FileCacheDir("", 123))
	require.Equal(t, "", FileCacheDir(base, 0))
	require.Equal(t, "", FileCacheDir(base, -1))
	require.Equal(t, "", KeyframeCachePath(base, 0))
}

func TestKeyframeCacheRoundtrip(t *testing.T) {
	dir := t.TempDir()
	keys := []float64{0, 4, 8}
	require.NoError(t, StoreCachedKeyframes(dir, 7, "etag-01", keys))

	got, ok := LoadCachedKeyframes(dir, 7, "etag-01")
	require.True(t, ok)
	require.Equal(t, keys, got)
}

func TestKeyframeCacheEtagMismatchEvictsFile(t *testing.T) {
	dir := t.TempDir()
	keys := []float64{0, 4, 8}
	require.NoError(t, StoreCachedKeyframes(dir, 7, "etag-01", keys))
	// A sibling artifact in the same file dir must go too.
	require.NoError(t, os.WriteFile(filepath.Join(FileCacheDir(dir, 7), "other.bin"), []byte("x"), 0644))

	_, ok := LoadCachedKeyframes(dir, 7, "other")
	require.False(t, ok)
	_, err := os.Stat(FileCacheDir(dir, 7))
	require.True(t, os.IsNotExist(err))
}

func TestKeyframeCacheGarbage(t *testing.T) {
	dir := t.TempDir()
	p := KeyframeCachePath(dir, 7)
	require.NoError(t, os.MkdirAll(filepath.Dir(p), 0755))
	require.NoError(t, os.WriteFile(p, []byte("not-json"), 0644))

	_, ok := LoadCachedKeyframes(dir, 7, "etag-01")
	require.False(t, ok)
}

func TestManagerCopyCacheHit(t *testing.T) {
	cfgDir := t.TempDir()
	keys := []float64{0, 4, 8}
	require.NoError(t, StoreCachedKeyframes(cfgDir, 7, "etag-hit", keys))

	m := newCopyManagerWithEtag(t, copyProbeJSON, "", true, cfgDir, "etag-hit")
	segs, ok := m.EnsureCopySegments()
	require.True(t, ok)
	require.Len(t, segs, 3)
}
