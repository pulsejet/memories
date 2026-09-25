package core

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

func stubProbe(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	require.NoError(t, os.WriteFile(filepath.Join(dir, "out.json"), []byte(
		`{"streams":[{"codec_type":"video","codec_name":"h264","width":64,"height":64,"avg_frame_rate":"30/1","duration":"1","bit_rate":"100"}],"format":{}}`,
	), 0644))
	bin := filepath.Join(dir, "ffprobe")
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\ncat "+filepath.Join(dir, "out.json")+"\n"), 0755))
	return bin
}

func TestManagerStreamsInitialized(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	m, err := NewManager(NewManagerArgs{
		C:             cfg,
		ManagerParams: ManagerParams{URL: "http://localhost/input.mp4", StreamID: "id"},
		Generation:    1,
		Idle:          make(chan IdleEvent, 1),
	})
	require.NoError(t, err)
	defer m.Destroy()

	require.NotEmpty(t, m.streams)
	for _, s := range m.streams {
		require.NotNil(t, s.chunks)
		require.NotNil(t, s.stop)
	}
}

func TestRegistryRefreshesToken(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	reg := NewRegistry(cfg, make(chan IdleEvent, 16))

	m1, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-1", ServiceToken: "tok-1"})
	require.NoError(t, err)
	defer m1.Destroy()
	require.Equal(t, "tok-1", m1.getServiceToken())

	// Same file, new short-lived token: same manager, refreshed token.
	m2, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-1", ServiceToken: "tok-2"})
	require.NoError(t, err)
	require.Same(t, m1, m2)
	require.Equal(t, "tok-2", m2.getServiceToken())
}

func TestRegistryStaleRemove(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	reg := NewRegistry(cfg, make(chan IdleEvent, 16))

	m1, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-1"})
	require.NoError(t, err)

	reg.Remove("s", m1.generation)
	m1.Destroy()

	m2, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-1"})
	require.NoError(t, err)
	defer m2.Destroy()
	require.NotSame(t, m1, m2)

	reg.Remove("s", m1.generation)

	got, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-1"})
	require.NoError(t, err)
	require.Same(t, m2, got)
}

func TestRegistryEtagMismatch(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	reg := NewRegistry(cfg, make(chan IdleEvent, 16))

	m1, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-1"})
	require.NoError(t, err)
	defer m1.Destroy()

	// Same session and path, new etag: fresh manager.
	m2, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/input.mp4", StreamID: "s", FileID: 7, Etag: "etag-2"})
	require.NoError(t, err)
	defer m2.Destroy()
	require.NotSame(t, m1, m2)

	// Same session and etag, moved URL: fresh manager.
	m3, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/moved.mp4", StreamID: "s", FileID: 7, Etag: "etag-2"})
	require.NoError(t, err)
	defer m3.Destroy()
	require.NotSame(t, m2, m3)

	// Steady state hits the cache.
	got, err := reg.GetOrCreate(ManagerParams{URL: "http://localhost/moved.mp4", StreamID: "s", FileID: 7, Etag: "etag-2"})
	require.NoError(t, err)
	require.Same(t, m3, got)
}

func TestManagerLiveDownloadsToTempFile(t *testing.T) {
	var gotToken string
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotToken = r.Header.Get(ServiceTokenHeader)
		w.Header().Set("Content-Type", "video/mp4")
		_, _ = w.Write([]byte("live-video-bytes"))
	}))
	defer upstream.Close()

	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	m, err := NewManager(NewManagerArgs{
		C: cfg,
		ManagerParams: ManagerParams{
			URL:      upstream.URL + "/index.php/apps/memories/api/video/livephoto/7?liveid=self__trailer",
			StreamID: "live", ServiceToken: "tok-1", UsesTemp: true,
			TConfig: config.TCfg{ChunkSize: 3},
		},
		Generation: 1,
		Idle:       make(chan IdleEvent, 1),
	})
	require.NoError(t, err)
	defer m.Destroy()

	require.Equal(t, "tok-1", gotToken)
	require.True(t, m.usesTemp)
	require.NotEqual(t, m.url, m.input)
	require.Equal(t, m.input, m.ffmpegInput())
	require.Empty(t, m.inputHeaders())
	require.FileExists(t, m.input)
	data, err := os.ReadFile(m.input)
	require.NoError(t, err)
	require.Equal(t, "live-video-bytes", string(data))

	// ffmpeg gets the temp file, never the remote URL.
	for _, s := range m.streams {
		spec := s.spec(0, true)
		require.Equal(t, m.input, spec.Input)
		require.Empty(t, spec.Headers)
		break
	}
}

func TestManagerLiveDownloadFailure(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	}))
	defer upstream.Close()

	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	_, err := NewManager(NewManagerArgs{
		C: cfg,
		ManagerParams: ManagerParams{
			URL:      upstream.URL + "/livephoto/7?liveid=self__trailer",
			StreamID: "live-fail", ServiceToken: "tok-1", UsesTemp: true,
			TConfig: config.TCfg{ChunkSize: 3},
		},
		Generation: 1,
		Idle:       make(chan IdleEvent, 1),
	})
	require.Error(t, err)
}

func TestManagerNonLiveStillStreams(t *testing.T) {
	cfg := config.Defaults("test")
	cfg.TempDir = t.TempDir()
	cfg.FFprobe = stubProbe(t)

	m, err := NewManager(NewManagerArgs{
		C: cfg,
		ManagerParams: ManagerParams{
			URL: "http://localhost/input.mp4", StreamID: "plain",
			ServiceToken: "tok-1", TConfig: config.TCfg{ChunkSize: 3},
		},
		Generation: 1,
		Idle:       make(chan IdleEvent, 1),
	})
	require.NoError(t, err)
	defer m.Destroy()

	require.False(t, m.usesTemp)
	require.Equal(t, m.url, m.ffmpegInput())
	require.Contains(t, m.inputHeaders(), "tok-1")
	for _, s := range m.streams {
		require.Equal(t, m.url, s.spec(0, true).Input)
		break
	}
}
