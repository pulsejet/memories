package api

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

func testServer(t *testing.T, mutate func(*config.Config)) *Server {
	t.Helper()
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFmpeg, cfg.FFprobe = "/bin/ffmpeg", "/bin/ffprobe"
	if mutate != nil {
		mutate(cfg)
	}
	return NewServer(cfg)
}

func enc(p string) string {
	return strings.ReplaceAll(p, "/", "%2F")
}

func TestSplitPath(t *testing.T) {
	sid, dir, leaf, ok := splitPath("/abc//files/x.mp4/index.m3u8")
	require.True(t, ok)
	require.Equal(t, "abc", sid)
	require.Equal(t, "/files/x.mp4", dir)
	require.Equal(t, "index.m3u8", leaf)

	// Equivalent spellings canonicalize to the same triple
	for _, p := range []string{"/abc/files/x.mp4/index.m3u8", "/abc/files/x.mp4/index.m3u8/", "/abc/./files/x.mp4/index.m3u8"} {
		sid, dir, leaf, ok := splitPath(p)
		require.True(t, ok, p)
		require.Equal(t, "abc", sid, p)
		require.Equal(t, "/files/x.mp4", dir, p)
		require.Equal(t, "index.m3u8", leaf, p)
	}

	_, _, _, ok = splitPath("/onlyone")
	require.False(t, ok)
	_, _, _, ok = splitPath("/a/b")
	require.False(t, ok)
	_, _, _, ok = splitPath("/")
	require.False(t, ok)
}

func TestBadURLs(t *testing.T) {
	s := testServer(t, nil)
	for _, target := range []string{"/", "/onlyone", "/a/b"} {
		r := httptest.NewRequest("GET", target, nil)
		w := httptest.NewRecorder()
		s.routes().ServeHTTP(w, r)
		require.Equal(t, http.StatusBadRequest, w.Code, target)
	}
}

func TestUnconfigured(t *testing.T) {
	s := testServer(t, nil)
	r := httptest.NewRequest("GET", "/s/%2Fa%2Fb.ts/x.ts", nil)
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusServiceUnavailable, w.Code)
}

func TestEndpoint(t *testing.T) {
	s := testServer(t, nil)

	path := filepath.Join(t.TempDir(), "f.mp4")
	require.NoError(t, os.WriteFile(path, []byte("12345"), 0644))

	r := httptest.NewRequest("GET", "/test/"+enc(path)+"/test", nil)
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)

	var body struct {
		Version string `json:"version"`
		Size    int    `json:"size"`
	}
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Equal(t, "test", body.Version)
	require.Equal(t, 5, body.Size)
}

func TestStoryboardNoEtag(t *testing.T) {
	dir := t.TempDir()
	out := filepath.Join(dir, "out.json")
	require.NoError(t, os.WriteFile(out, []byte(
		`{"streams":[{"codec_type":"video","codec_name":"h264","width":64,"height":64,"avg_frame_rate":"30/1","duration":"1","bit_rate":"100"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+out+"\n"), 0755))

	s := testServer(t, func(c *config.Config) {
		c.Configured = true
		c.FFprobe = bin
	})

	// No etag header means no cache dir, so no storyboard.
	for _, leaf := range []string{"storyboard.vtt", "storyboard-0.jpg", "storyboard-x.jpg"} {
		r := httptest.NewRequest("GET", "/s/%2Finput.mp4/"+leaf, nil)
		w := httptest.NewRecorder()
		s.routes().ServeHTTP(w, r)
		require.Equal(t, http.StatusNotFound, w.Code, leaf)
	}
}

func TestConfigReload(t *testing.T) {
	s := testServer(t, func(c *config.Config) { c.Configured = true })

	r := httptest.NewRequest("POST", "/config/%2Fconfig/config", strings.NewReader(`{"chunkSize":7,"qf":24}`))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
	require.Equal(t, 7, s.cfg.ChunkSize)
	require.True(t, s.cfg.Configured)

	before := s.cfg.ChunkSize
	r = httptest.NewRequest("POST", "/config/%2Fconfig/config", strings.NewReader(`{"chunkSize":0}`))
	w = httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusInternalServerError, w.Code)
	require.Equal(t, before, s.cfg.ChunkSize)
}

func TestConfigReloadIgnoresPostedCacheDirWhenEnvSet(t *testing.T) {
	t.Setenv("CACHE_DIR", "/from-env")
	s := testServer(t, func(c *config.Config) {
		c.Configured = true
		c.CacheDir = "/from-env"
	})

	r := httptest.NewRequest("POST", "/config/%2Fconfig/config", strings.NewReader(`{"chunkSize":7,"cacheDir":"/from-php"}`))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
	require.Equal(t, 7, s.cfg.ChunkSize)
	require.Equal(t, "/from-env", s.cfg.CacheDir)
}

func TestCreateTempLimit(t *testing.T) {
	s := testServer(t, nil)
	s.cfg.MaxUploadSize = 1024

	r := httptest.NewRequest("POST", "/xyz/%2Fcreate/ignore", strings.NewReader(strings.Repeat("x", 2048)))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusRequestEntityTooLarge, w.Code)
}

func TestCreateTemp(t *testing.T) {
	s := testServer(t, nil)

	r := httptest.NewRequest("POST", "/xyz/%2Fcreate/ignore", strings.NewReader("hello"))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)

	var body struct {
		Path string `json:"path"`
	}
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Contains(t, body.Path, "xyz-govod-temp-")
	content, err := os.ReadFile(body.Path)
	require.NoError(t, err)
	require.Equal(t, "hello", string(content))
}

func TestVersionGuard(t *testing.T) {
	s := testServer(t, func(c *config.Config) { c.VersionMonitor = true })
	go func() { <-s.idle }()

	r := httptest.NewRequest("GET", "/test/%2Fnone/test", nil)
	r.Header.Set("X-Go-Vod-Version", "wrong")
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusServiceUnavailable, w.Code)
	require.Equal(t, 12, s.exitCode)

	r = httptest.NewRequest("GET", "/test/%2Fnone/test", nil)
	r.Header.Set("X-Go-Vod-Version", "test")
	w = httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
}
