package config

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/require"
)

func TestDefaultsValid(t *testing.T) {
	c := Defaults("test")
	c.FFmpeg, c.FFprobe, c.TempDir = "/bin/ffmpeg", "/bin/ffprobe", t.TempDir()
	require.NoError(t, c.Validate())
	require.Equal(t, int64(4<<30), c.MaxUploadSize)
	require.Equal(t, "/dev/dri/renderD128", c.VAAPIDevice)
}

func TestLoadFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "valid.json")
	require.NoError(t, os.WriteFile(path, []byte(
		`{"bind": ":49999", "chunkSize": 5, "vaapi": true}`,
	), 0644))

	c := Defaults("test")
	require.NoError(t, c.LoadFile(path))
	require.True(t, c.Configured)
	require.Equal(t, ":49999", c.Bind)
	require.Equal(t, 5, c.ChunkSize)
	require.True(t, c.VAAPI)
}

func TestLoadFileMissing(t *testing.T) {
	c := Defaults("test")
	require.Error(t, c.LoadFile(filepath.Join(t.TempDir(), "does-not-exist.json")))
	require.False(t, c.Configured)
}

func TestLoadFileBadJSON(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "bad.json")
	require.NoError(t, os.WriteFile(path, []byte("{oops"), 0644))
	require.Error(t, Defaults("test").LoadFile(path))
}

func TestValidate(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*Config)
		ok     bool
	}{
		{"valid", func(*Config) {}, true},
		{"zero chunk", func(c *Config) { c.ChunkSize = 0 }, false},
		{"bad buffers", func(c *Config) { c.GoalBufferMax = 0 }, false},
		{"negative lookbehind", func(c *Config) { c.LookBehind = -1 }, false},
		{"missing paths", func(c *Config) { c.FFmpeg, c.FFprobe, c.TempDir = "", "", "" }, false},
		{"zero upload", func(c *Config) { c.MaxUploadSize = 0 }, false},
		{"vaapi and nvenc", func(c *Config) { c.VAAPI, c.NVENC, c.NVENCScale = true, true, "cuda" }, false},
		{"bad nvenc scale", func(c *Config) { c.NVENCScale = "vulkan" }, false},
		{"nvenc without scale", func(c *Config) { c.NVENC = true }, false},
		{"nvenc cuda", func(c *Config) { c.NVENC, c.NVENCScale = true, "cuda" }, true},
		{"nvenc npp", func(c *Config) { c.NVENC, c.NVENCScale = true, "npp" }, true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			c := Defaults("test")
			c.FFmpeg, c.FFprobe, c.TempDir = "/bin/ffmpeg", "/bin/ffprobe", t.TempDir()
			tc.mutate(c)
			if tc.ok {
				require.NoError(t, c.Validate())
			} else {
				require.Error(t, c.Validate())
			}
		})
	}
}

func TestAutoDetect(t *testing.T) {
	dir := t.TempDir()
	for _, bin := range []string{"ffmpeg", "ffprobe"} {
		require.NoError(t, os.WriteFile(filepath.Join(dir, bin), []byte("#!/bin/sh\n"), 0755))
	}
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))

	c := Defaults("test")
	require.NoError(t, c.AutoDetect())
	require.Equal(t, filepath.Join(dir, "ffmpeg"), c.FFmpeg)
	require.Equal(t, filepath.Join(dir, "ffprobe"), c.FFprobe)
	require.NotEmpty(t, c.TempDir)

	preset := Defaults("test")
	preset.FFmpeg = "/custom/ffmpeg"
	require.NoError(t, preset.AutoDetect())
	require.Equal(t, "/custom/ffmpeg", preset.FFmpeg)
}

func TestCacheDir(t *testing.T) {
	t.Setenv("CACHE_DIR", "")
	c := Defaults("test")
	c.TempDir = t.TempDir()
	require.NoError(t, c.AutoDetect())
	require.Equal(t, "", c.CacheDir)
	require.Equal(t, filepath.Join(os.TempDir(), "go-vod-cache"), c.ResolvedCacheDir())

	t.Setenv("CACHE_DIR", "/from-env")
	c = Defaults("test")
	c.TempDir = t.TempDir()
	require.NoError(t, c.AutoDetect())
	require.Equal(t, "/from-env", c.CacheDir)

	c = Defaults("test")
	c.TempDir = t.TempDir()
	require.Equal(t, filepath.Join(os.TempDir(), "go-vod-cache"), c.ResolvedCacheDir())
}
