// Package config loads and validates go-vod configuration.
// Loading and validation return errors instead of exiting the process.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"

	"github.com/go-playground/validator/v10"
)

type Config struct {
	Bind string `json:"bind" validate:"required"`

	FFmpeg   string `json:"ffmpeg" validate:"required"`
	FFprobe  string `json:"ffprobe" validate:"required"`
	TempDir  string `json:"tempdir" validate:"required"`
	CacheDir string `json:"cacheDir"`

	MaxUploadSize int64 `json:"maxUploadSize" validate:"gte=1"`

	ChunkSize       int `json:"chunkSize" validate:"gte=1"`
	LookBehind      int `json:"lookBehind" validate:"gte=0"`
	GoalBufferMin   int `json:"goalBufferMin" validate:"gte=0"`
	GoalBufferMax   int `json:"goalBufferMax" validate:"gtefield=GoalBufferMin"`
	StreamIdleTime  int `json:"streamIdleTime" validate:"gte=0"`
	ManagerIdleTime int `json:"managerIdleTime" validate:"gte=0"`

	QF int `json:"qf"`

	VAAPI         bool   `json:"vaapi"`
	VAAPILowPower bool   `json:"vaapiLowPower"`
	VAAPIDevice   string `json:"vaapiDevice"`

	NVENC           bool   `json:"nvenc"`
	NVENCTemporalAQ bool   `json:"nvencTemporalAQ"`
	NVENCScale      string `json:"nvencScale" validate:"required_if=NVENC true,omitempty,oneof=cuda npp"`

	UseTranspose     bool `json:"useTranspose"`
	ForceSwTranspose bool `json:"forceSwTranspose"`

	UseGopSize bool `json:"useGopSize"`

	Version        string `json:"-"`
	VersionMonitor bool   `json:"-"`
	Configured     bool   `json:"-"`
}

func Defaults(version string) *Config {
	return &Config{
		Version:         version,
		Bind:            ":47788",
		ChunkSize:       3,
		LookBehind:      3,
		GoalBufferMin:   1,
		GoalBufferMax:   4,
		StreamIdleTime:  60,
		ManagerIdleTime: 60,
		MaxUploadSize:   4 << 30,
		VAAPIDevice:     "/dev/dri/renderD128",
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
	c.Configured = true
	return nil
}

var validate = validator.New()

func (c *Config) Validate() error {
	if err := validate.Struct(c); err != nil {
		return err
	}
	if c.VAAPI && c.NVENC {
		return errors.New("vaapi and nvenc are mutually exclusive")
	}
	return nil
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
	if v, ok := os.LookupEnv("CACHE_DIR"); ok && v != "" {
		c.CacheDir = v
	}
	return nil
}

func (c *Config) ResolvedCacheDir() string {
	if c.CacheDir != "" {
		return c.CacheDir
	}
	return filepath.Join(os.TempDir(), "go-vod-cache")
}
