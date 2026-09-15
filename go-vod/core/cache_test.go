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

	m, err := NewManager(cfg, "input.mp4", "id", etag, 1, make(chan IdleEvent, 1))
	require.NoError(t, err)
	t.Cleanup(m.Destroy)
	return m
}

func TestCacheFileDir(t *testing.T) {
	base := t.TempDir()
	d1 := CacheFileDir(base, "etag-1")
	d2 := CacheFileDir(base, "etag-1")
	require.Equal(t, d1, d2)
	require.NotEqual(t, d1, CacheFileDir(base, "etag-2"))

	rel, err := filepath.Rel(base, d1)
	require.NoError(t, err)
	require.Equal(t, 3, len(strings.Split(rel, string(os.PathSeparator))))
	require.Equal(t, KeyframeCacheFile, filepath.Base(KeyframeCachePath(base, "etag-1")))

	require.Equal(t, "", CacheFileDir("", "etag-1"))
	require.Equal(t, "", CacheFileDir(base, ""))
	require.Equal(t, "", KeyframeCachePath(base, ""))
}

func TestKeyframeCacheRoundtrip(t *testing.T) {
	dir := t.TempDir()
	keys := []float64{0, 4, 8}
	require.NoError(t, StoreCachedKeyframes(dir, "etag-01", keys))

	got, ok := LoadCachedKeyframes(dir, "etag-01")
	require.True(t, ok)
	require.Equal(t, keys, got)

	_, ok = LoadCachedKeyframes(dir, "other")
	require.False(t, ok)
}

func TestKeyframeCacheGarbage(t *testing.T) {
	dir := t.TempDir()
	p := KeyframeCachePath(dir, "etag-01")
	require.NoError(t, os.MkdirAll(filepath.Dir(p), 0755))
	require.NoError(t, os.WriteFile(p, []byte("not-json"), 0644))

	_, ok := LoadCachedKeyframes(dir, "etag-01")
	require.False(t, ok)
}

func TestManagerCopyCacheHit(t *testing.T) {
	cfgDir := t.TempDir()
	keys := []float64{0, 4, 8}
	require.NoError(t, StoreCachedKeyframes(cfgDir, "etag-hit", keys))

	m := newCopyManagerWithEtag(t, copyProbeJSON, "", true, cfgDir, "etag-hit")
	segs, ok := m.CopySegments()
	require.True(t, ok)
	require.Len(t, segs, 3)
}
