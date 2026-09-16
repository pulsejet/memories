package api

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
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

func postVod(s *Server, body string) *httptest.ResponseRecorder {
	r := httptest.NewRequest("POST", "/vod", strings.NewReader(body))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	return w
}

func TestHealth(t *testing.T) {
	s := testServer(t, nil)

	r := httptest.NewRequest("GET", "/health", nil)
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)

	var body struct {
		Status  string `json:"status"`
		Version string `json:"version"`
	}
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Equal(t, "ok", body.Status)
	require.Equal(t, "test", body.Version)
}

func TestVodBadRequests(t *testing.T) {
	s := testServer(t, func(c *config.Config) { c.Configured = true })

	w := postVod(s, "nope")
	require.Equal(t, http.StatusBadRequest, w.Code)

	// Missing required fields (fileid/etag are optional).
	for _, body := range []string{
		`{}`,
		`{"client":"","path":"/x","profile":"test"}`,
		`{"client":"c","path":"","profile":"test"}`,
		`{"client":"c","path":"/x","profile":""}`,
	} {
		w := postVod(s, body)
		require.Equal(t, http.StatusBadRequest, w.Code, body)
	}

	w = postVod(s, `{"client":"c","path":"/x","profile":"bogus"}`)
	require.Equal(t, http.StatusNotFound, w.Code)

	// GET is gone entirely.
	r := httptest.NewRequest("GET", "/vod", nil)
	w = httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusMethodNotAllowed, w.Code)
}

func TestVodUnconfigured(t *testing.T) {
	s := testServer(t, nil)
	w := postVod(s, `{"client":"c","path":"/x","profile":"index.m3u8"}`)
	require.Equal(t, http.StatusServiceUnavailable, w.Code)
}

func TestVodTestProfile(t *testing.T) {
	s := testServer(t, nil)

	path := filepath.Join(t.TempDir(), "f.mp4")
	require.NoError(t, os.WriteFile(path, []byte("12345"), 0644))

	w := postVod(s, `{"client":"test","fileid":7,"etag":"e","path":`+strconv.Quote(path)+`,"profile":"test"}`)
	require.Equal(t, http.StatusOK, w.Code)

	var body struct {
		Version string `json:"version"`
		Size    int    `json:"size"`
	}
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Equal(t, "test", body.Version)
	require.Equal(t, 5, body.Size)
}

func TestVodStoryboardNoFileID(t *testing.T) {
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

	// No fileid means no cache dir, so no storyboard.
	for _, leaf := range []string{"storyboard.vtt", "storyboard-0.jpg", "storyboard-x.jpg"} {
		w := postVod(s, `{"client":"s","path":"/input.mp4","profile":"`+leaf+`"}`)
		require.Equal(t, http.StatusNotFound, w.Code, leaf)
	}
}

func TestConfigReload(t *testing.T) {
	s := testServer(t, func(c *config.Config) { c.Configured = true })

	r := httptest.NewRequest("POST", "/config", strings.NewReader(`{"chunkSize":7,"qf":24}`))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
	require.Equal(t, 7, s.cfg.ChunkSize)
	require.True(t, s.cfg.Configured)

	before := s.cfg.ChunkSize
	r = httptest.NewRequest("POST", "/config", strings.NewReader(`{"chunkSize":0}`))
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

	r := httptest.NewRequest("POST", "/config", strings.NewReader(`{"chunkSize":7,"cacheDir":"/from-php"}`))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
	require.Equal(t, 7, s.cfg.ChunkSize)
	require.Equal(t, "/from-env", s.cfg.CacheDir)
}

func TestCreateTempLimit(t *testing.T) {
	s := testServer(t, nil)
	s.cfg.MaxUploadSize = 1024

	r := httptest.NewRequest("POST", "/create", strings.NewReader(strings.Repeat("x", 2048)))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusRequestEntityTooLarge, w.Code)
}

func TestCreateTemp(t *testing.T) {
	s := testServer(t, nil)

	r := httptest.NewRequest("POST", "/create", strings.NewReader("hello"))
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)

	var body struct {
		Path string `json:"path"`
	}
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Contains(t, body.Path, "govod-temp-")
	content, err := os.ReadFile(body.Path)
	require.NoError(t, err)
	require.Equal(t, "hello", string(content))
}

func TestVersionGuard(t *testing.T) {
	s := testServer(t, func(c *config.Config) { c.VersionMonitor = true })
	go func() { <-s.idle }()

	r := httptest.NewRequest("POST", "/vod", strings.NewReader(`{"client":"c","path":"/x","profile":"test"}`))
	r.Header.Set("X-Go-Vod-Version", "wrong")
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusServiceUnavailable, w.Code)
	require.Equal(t, 12, s.exitCode)

	r = httptest.NewRequest("POST", "/vod", strings.NewReader(`{"client":"c","path":"/x","profile":"test"}`))
	r.Header.Set("X-Go-Vod-Version", "test")
	w = httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
}
