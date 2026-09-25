package api

import (
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/core"
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

func newTestParams(client, fileURL string, qf int) core.ManagerParams {
	return core.ManagerParams{
		StreamID: client,
		URL:      fileURL,
		TConfig:  config.TCfg{ChunkSize: 3, QF: qf},
	}
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
	s := testServer(t, nil)

	w := postVod(s, "nope")
	require.Equal(t, http.StatusBadRequest, w.Code)

	// Missing required fields (etag is optional).
	for _, body := range []string{
		`{}`,
		`{"client":"","fileid":7,"profile":"test"}`,
		`{"client":"c","profile":"test"}`,
		`{"client":"c","fileid":0,"profile":"test"}`,
		`{"client":"c","fileid":7,"profile":""}`,
	} {
		w := postVod(s, body)
		require.Equal(t, http.StatusBadRequest, w.Code, body)
	}

	for _, body := range []string{
		`{"client":"c","fileid":7,"profile":"index.m3u8","config":{"chunkSize":0}}`,
		`{"client":"c","fileid":7,"profile":"index.m3u8","config":{"chunkSize":3,"vaapi":true,"nvenc":true,"nvencScale":"cuda"}}`,
		`{"client":"c","fileid":7,"profile":"index.m3u8","config":{"chunkSize":3,"nvenc":true}}`,
		`{"client":"c","fileid":7,"profile":"index.m3u8","config":{"chunkSize":3,"nvenc":true,"nvencScale":"vulkan"}}`,
	} {
		w := postVod(s, body)
		require.Equal(t, http.StatusBadRequest, w.Code, body)
	}

	w = postVod(s, `{"client":"c","fileid":7,"profile":"bogus","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusNotFound, w.Code)

	// GET is gone entirely.
	r := httptest.NewRequest("GET", "/vod", nil)
	w = httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusMethodNotAllowed, w.Code)
}

func TestVodTestProfile(t *testing.T) {
	var gotToken, gotMethod, gotPath string
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotToken, gotMethod, gotPath = r.Header.Get(core.ServiceTokenHeader), r.Method, r.URL.Path
		http.ServeContent(w, r, "f.mp4", time.Now(), strings.NewReader("12345"))
	}))
	defer upstream.Close()

	s := testServer(t, func(c *config.Config) {
		c.NextcloudURL = upstream.URL
	})

	w := postVod(s, `{"client":"test","fileid":7,"etag":"e","serviceToken":"tok-1","profile":"test","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusOK, w.Code)

	var body struct {
		Version string `json:"version"`
		Size    int    `json:"size"`
	}
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Equal(t, "test", body.Version)
	require.Equal(t, 5, body.Size)
	require.Equal(t, "HEAD", gotMethod)
	require.Equal(t, "/index.php/apps/memories/api/stream/7", gotPath)
	require.Equal(t, "tok-1", gotToken)

	// Unreachable upstream reports size zero without failing the version check.
	s.cfg.NextcloudURL = "http://127.0.0.1:1"
	w = postVod(s, `{"client":"test","fileid":7,"etag":"e","serviceToken":"tok-1","profile":"test","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusOK, w.Code)
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &body))
	require.Equal(t, 0, body.Size)
}

func TestVodRequiresFileID(t *testing.T) {
	dir := t.TempDir()
	out := filepath.Join(dir, "out.json")
	require.NoError(t, os.WriteFile(out, []byte(
		`{"streams":[{"codec_type":"video","codec_name":"h264","width":64,"height":64,"avg_frame_rate":"30/1","duration":"1","bit_rate":"100"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+out+"\n"), 0755))

	s := testServer(t, func(c *config.Config) {
		c.FFprobe = bin
	})

	// Without a fileid there is no URL to stream and no cache dir.
	for _, leaf := range []string{"storyboard.vtt", "storyboard-0.jpg", "index.m3u8"} {
		w := postVod(s, `{"client":"s","profile":"`+leaf+`","config":{"chunkSize":3}}`)
		require.Equal(t, http.StatusBadRequest, w.Code, leaf)
	}
}

func TestVodCodecsQueryParam(t *testing.T) {
	dir := t.TempDir()
	out := filepath.Join(dir, "out.json")
	require.NoError(t, os.WriteFile(out, []byte(
		`{"streams":[{"codec_type":"video","codec_name":"hevc","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"12","bit_rate":"1000000"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+out+"\n"), 0755))

	s := testServer(t, func(c *config.Config) {
		c.FFprobe = bin
	})

	// Without playable codecs an HEVC source only offers a transcode.
	w := postVod(s, `{"client":"s1","fileid":7,"profile":"index.m3u8","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusOK, w.Code)
	require.Contains(t, w.Body.String(), "max.m3u8")
	require.NotContains(t, w.Body.String(), "direct.m3u8")

	w = postVod(s, `{"client":"s2","fileid":7,"profile":"index.m3u8","query":{"token":"abc","codecs":"h264,hevc"},"config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusOK, w.Code)
	require.Contains(t, w.Body.String(), "direct.m3u8")
	require.NotContains(t, w.Body.String(), "max.m3u8")
	require.Contains(t, w.Body.String(), "direct.m3u8?token=abc")
	require.NotContains(t, w.Body.String(), "codecs=")
}

func TestVodEmbedFullVideo(t *testing.T) {
	dir := t.TempDir()
	out := filepath.Join(dir, "out.json")
	require.NoError(t, os.WriteFile(out, []byte(
		`{"streams":[{"codec_type":"video","codec_name":"h264","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"12","bit_rate":"1000000"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+out+"\n"), 0755))

	s := testServer(t, func(c *config.Config) {
		c.FFprobe = bin
	})

	// Playable inline data is answered with bytes, never a redirect.
	raw := "embed-video-bytes"
	body := `{"client":"emb","profile":"max.mp4","fileData":"` +
		base64.StdEncoding.EncodeToString([]byte(raw)) +
		`","query":{"codecs":"h264"},"config":{"chunkSize":3}}`
	w := postVod(s, body)
	require.Equal(t, http.StatusOK, w.Code)
	require.Empty(t, w.Header().Get(core.StreamOriginalHeader))
	require.Equal(t, raw, w.Body.String())

	// Invalid base64 is rejected.
	w = postVod(s, `{"client":"emb","profile":"max.mp4","fileData":"!!!","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusBadRequest, w.Code)

	// Only progressive MP4 is served from inline data.
	w = postVod(s, `{"client":"emb","profile":"index.m3u8","fileData":"eA==","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusNotFound, w.Code)

	// Zero fileid without inline data is still rejected.
	w = postVod(s, `{"client":"emb","profile":"max.mp4","config":{"chunkSize":3}}`)
	require.Equal(t, http.StatusBadRequest, w.Code)
}

func TestVodQueryEncode(t *testing.T) {
	require.Equal(t, "", VodQuery{}.Encode())
	require.Equal(t, "?token=abc", VodQuery{Token: "abc", Codecs: "h264,hevc"}.Encode())
	require.Equal(t, "?albums=xyz&token=abc", VodQuery{Albums: "xyz", Token: "abc", Codecs: "h264"}.Encode())
}

func TestNullQueryValues(t *testing.T) {
	var req VodRequest
	require.NoError(t, json.Unmarshal([]byte(`{"client":"c","fileid":7,"profile":"test","query":{"albums":null,"token":"abc","codecs":null},"config":{"chunkSize":3}}`), &req))
	require.Equal(t, "", req.Query.Albums)
	require.Equal(t, "abc", req.Query.Token)
	require.Equal(t, "?token=abc", req.Query.Encode())
}

func TestVodReusesManagerOnConfigChange(t *testing.T) {
	dir := t.TempDir()
	out := filepath.Join(dir, "out.json")
	require.NoError(t, os.WriteFile(out, []byte(
		`{"streams":[{"codec_type":"video","codec_name":"h264","width":1280,"height":720,"avg_frame_rate":"30/1","duration":"12","bit_rate":"1000000"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+out+"\n"), 0755))

	s := testServer(t, func(c *config.Config) {
		c.FFprobe = bin
		c.NextcloudURL = "http://localhost"
	})
	fileURL := s.cfg.FileURL(7)

	w := postVod(s, `{"client":"cfg","fileid":7,"profile":"index.m3u8","config":{"chunkSize":3,"qf":24}}`)
	require.Equal(t, http.StatusOK, w.Code)
	m1, err := s.reg.GetOrCreate(newTestParams("cfg", fileURL, 24))
	require.NoError(t, err)

	w = postVod(s, `{"client":"cfg","fileid":7,"profile":"index.m3u8","config":{"chunkSize":3,"qf":30}}`)
	require.Equal(t, http.StatusOK, w.Code)
	m2, err := s.reg.GetOrCreate(newTestParams("cfg", fileURL, 30))
	require.NoError(t, err)
	require.Same(t, m1, m2)
}

func TestVersionGuard(t *testing.T) {
	s := testServer(t, func(c *config.Config) { c.VersionMonitor = true })
	go func() { <-s.idle }()

	r := httptest.NewRequest("POST", "/vod", strings.NewReader(`{"client":"c","fileid":7,"profile":"test","config":{"chunkSize":3}}`))
	r.Header.Set("X-Go-Vod-Version", "wrong")
	w := httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusServiceUnavailable, w.Code)
	require.Equal(t, 12, s.exitCode)

	r = httptest.NewRequest("POST", "/vod", strings.NewReader(`{"client":"c","fileid":7,"profile":"test","config":{"chunkSize":3}}`))
	r.Header.Set("X-Go-Vod-Version", "test")
	w = httptest.NewRecorder()
	s.routes().ServeHTTP(w, r)
	require.Equal(t, http.StatusOK, w.Code)
}
