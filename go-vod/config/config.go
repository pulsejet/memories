// Package config loads and validates go-vod configuration.
// Loading and validation return errors instead of exiting the process.
package config

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"

	"github.com/go-playground/validator/v10"
)

type Config struct {
	Bind string `json:"bind" validate:"required"`

	FFmpeg    string `json:"ffmpeg" validate:"required"`
	FFprobe   string `json:"ffprobe" validate:"required"`
	TempDir   string `json:"tempdir" validate:"required"`
	CacheDir_ string `json:"cacheDir"`

	NextcloudURL string `json:"nextcloudUrl" validate:"required,http_url"`

	LookBehind      int `json:"lookBehind" validate:"gte=0"`
	GoalBufferMin   int `json:"goalBufferMin" validate:"gte=0"`
	GoalBufferMax   int `json:"goalBufferMax" validate:"gtefield=GoalBufferMin"`
	StreamIdleTime  int `json:"streamIdleTime" validate:"gte=0"`
	ManagerIdleTime int `json:"managerIdleTime" validate:"gte=0"`

	Version        string `json:"-"`
	VersionMonitor bool   `json:"-"`
}

func Defaults(version string) *Config {
	return &Config{
		Version:         version,
		Bind:            ":47788",
		LookBehind:      3,
		GoalBufferMin:   1,
		GoalBufferMax:   4,
		StreamIdleTime:  60,
		ManagerIdleTime: 60,
	}
}

func (c *Config) LoadFile(path string) error {
	content, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("open config %s: %w", path, err)
	}
	if err := json.Unmarshal(content, c); err != nil {
		return fmt.Errorf("parse config %s: %w", path, err)
	}
	return nil
}

var validate = validator.New()

func (c *Config) Validate() error {
	return validate.Struct(c)
}

func (c *Config) CacheDir() string {
	if v, ok := os.LookupEnv("CACHE_DIR"); ok && v != "" {
		return v
	}
	return c.CacheDir_
}

func (c *Config) FileURL(fileid int64) string {
	return strings.TrimSuffix(c.NextcloudURL, "/") +
		"/index.php/apps/memories/api/stream/" + strconv.FormatInt(fileid, 10)
}

func (c *Config) AutoDetect() error {
	if c.FFmpeg == "" {
		ffmpeg, err := exec.LookPath("ffmpeg")
		if err != nil {
			return fmt.Errorf("find ffmpeg: %w", err)
		}
		c.FFmpeg = ffmpeg
	}
	if c.FFprobe == "" {
		ffprobe, err := exec.LookPath("ffprobe")
		if err != nil {
			return fmt.Errorf("find ffprobe: %w", err)
		}
		c.FFprobe = ffprobe
	}
	if c.TempDir == "" {
		c.TempDir = os.TempDir() + "/go-vod"
	}
	if v, ok := os.LookupEnv("NEXTCLOUD_HOST"); ok && v != "" {
		c.NextcloudURL = v
	}
	return nil
}
