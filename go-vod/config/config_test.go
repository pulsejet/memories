package config

import (
	"encoding/json"
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
}

func TestLoadFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "valid.json")
	require.NoError(t, os.WriteFile(path, []byte(
		`{"bind": ":49999", "lookBehind": 5}`,
	), 0644))

	c := Defaults("test")
	require.NoError(t, c.LoadFile(path))
	require.Equal(t, ":49999", c.Bind)
	require.Equal(t, 5, c.LookBehind)
}

func TestLoadFileMissing(t *testing.T) {
	c := Defaults("test")
	require.Error(t, c.LoadFile(filepath.Join(t.TempDir(), "does-not-exist.json")))
}

func TestLoadFileBadJSON(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "bad.json")
	require.NoError(t, os.WriteFile(path, []byte("{oops"), 0644))
	require.Error(t, Defaults("test").LoadFile(path))
}

func TestConfigCacheDir(t *testing.T) {
	var c Config
	require.NoError(t, json.Unmarshal([]byte(`{"cacheDir":"/from-php"}`), &c))

	t.Setenv("CACHE_DIR", "/from-env")
	require.Equal(t, "/from-env", c.CacheDir())

	t.Setenv("CACHE_DIR", "")
	require.Equal(t, "/from-php", c.CacheDir())

	var empty Config
	require.NoError(t, json.Unmarshal([]byte(`{}`), &empty))
	require.Equal(t, "", empty.CacheDir())
}

func TestValidate(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*Config)
		ok     bool
	}{
		{"valid", func(*Config) {}, true},
		{"bad buffers", func(c *Config) { c.GoalBufferMax = 0 }, false},
		{"negative lookbehind", func(c *Config) { c.LookBehind = -1 }, false},
		{"missing paths", func(c *Config) { c.FFmpeg, c.FFprobe, c.TempDir = "", "", "" }, false},
		{"zero upload", func(c *Config) { c.MaxUploadSize = 0 }, false},
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
