// Package config loads and validates go-vod configuration.
// Loading and validation return errors instead of exiting the process.
package config

import (
	"crypto/tls"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"sync"

	"github.com/go-playground/validator/v10"
)

type Config struct {
	Bind string `json:"bind" validate:"required"`

	FFmpeg    string `json:"ffmpeg" validate:"required"`
	FFprobe   string `json:"ffprobe" validate:"required"`
	TempDir   string `json:"tempdir" validate:"required"`
	CacheDir_ string `json:"cacheDir"`

	NextcloudURL  string `json:"nextcloudUrl" validate:"required,http_url"`
	AllowInsecure bool   `json:"allowInsecure"`
	Bridge        Bridge `json:"bridge"`

	LookBehind      int `json:"lookBehind" validate:"gte=0"`
	GoalBufferMin   int `json:"goalBufferMin" validate:"gte=0"`
	GoalBufferMax   int `json:"goalBufferMax" validate:"gtefield=GoalBufferMin"`
	StreamIdleTime  int `json:"streamIdleTime" validate:"gte=0"`
	ManagerIdleTime int `json:"managerIdleTime" validate:"gte=0"`

	Version        string `json:"-"`
	VersionMonitor bool   `json:"-"`
}

// Bridge configures persistent PHP workers serving file reads.
type Bridge struct {
	Enabled bool `json:"enabled"`
	// MaxWorkers caps concurrent PHP workers; reads wait when all are busy.
	MaxWorkers int `json:"maxWorkers" validate:"gte=1"`
	// MinWorkers stay warm when idle, until their TTL.
	MinWorkers int `json:"minWorkers" validate:"gte=0,ltefield=MaxWorkers"`
	// WorkerTTL is the max lifetime of a worker in seconds. Keep it below
	// web server timeouts (e.g. fastcgi_read_timeout); PHP caps it at 300.
	WorkerTTL int `json:"workerTTL" validate:"gte=1,lte=300"`
	// IdleTime is how long workers above MinWorkers stay idle, in seconds.
	IdleTime int `json:"idleTime" validate:"gte=0"`
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
		Bridge: Bridge{
			Enabled:    true,
			MaxWorkers: 4,
			MinWorkers: 1,
			WorkerTTL:  30,
			IdleTime:   5,
		},
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
	if err := validate.Struct(c); err != nil {
		return err
	}
	if c.CacheDir() == "" {
		return fmt.Errorf("cache dir is required (set cacheDir or CACHE_DIR)")
	}
	return nil
}

func (c *Config) CacheDir() string {
	if v, ok := os.LookupEnv("CACHE_DIR"); ok && v != "" {
		return v
	}
	return c.CacheDir_
}

var insecureTransport = sync.OnceValue(func() *http.Transport {
	t := http.DefaultTransport.(*http.Transport).Clone()
	t.TLSClientConfig = &tls.Config{InsecureSkipVerify: true}
	return t
})

// Transport is the round tripper for calls to Nextcloud.
func (c *Config) Transport() http.RoundTripper {
	if c.AllowInsecure {
		return insecureTransport()
	}
	return http.DefaultTransport
}

func (c *Config) FileURL(fileid int64) string {
	return strings.TrimSuffix(c.NextcloudURL, "/") +
		"/index.php/apps/memories/api/stream/" + strconv.FormatInt(fileid, 10)
}

func (c *Config) LiveURL(fileid int64, liveid string) string {
	return strings.TrimSuffix(c.NextcloudURL, "/") +
		"/index.php/apps/memories/api/video/livephoto/" + strconv.FormatInt(fileid, 10) +
		"?liveid=" + url.QueryEscape(liveid)
}

func (c *Config) BridgeURL(fileid int64) string {
	return strings.TrimSuffix(c.NextcloudURL, "/") +
		"/index.php/apps/memories/api/video/bridge/" + strconv.FormatInt(fileid, 10)
}

func (c *Config) DescribeURL() string {
	return strings.TrimSuffix(c.NextcloudURL, "/") +
		"/index.php/apps/memories/api/describe"
}

func (c *Config) AutoDetect() error {
	// Detect general configuration
	if err := c.loadEnv(map[string]any{
		"NEXTCLOUD_HOST":           &c.NextcloudURL,
		"NEXTCLOUD_ALLOW_INSECURE": &c.AllowInsecure,
		"GO_VOD_FFMPEG_PATH":       &c.FFmpeg,
		"GO_VOD_FFPROBE_PATH":      &c.FFprobe,
		"GO_VOD_TEMP_DIR":          &c.TempDir,
	}); err != nil {
		return err
	}

	// Detect bridge configuration
	if err := c.loadEnv(map[string]any{
		"GO_VOD_BRIDGE_ENABLED":     &c.Bridge.Enabled,
		"GO_VOD_BRIDGE_MAX_WORKERS": &c.Bridge.MaxWorkers,
		"GO_VOD_BRIDGE_MIN_WORKERS": &c.Bridge.MinWorkers,
		"GO_VOD_BRIDGE_WORKER_TTL":  &c.Bridge.WorkerTTL,
		"GO_VOD_BRIDGE_IDLE_TIME":   &c.Bridge.IdleTime,
	}); err != nil {
		return err
	}

	// Detect binaries
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

	return nil
}

// loadEnv sets each pointer in vars from its non-empty environment variable.
func (c *Config) loadEnv(vars map[string]any) error {
	for env, dst := range vars {
		v, ok := os.LookupEnv(env)
		if !ok || v == "" {
			continue
		}
		var err error
		switch p := dst.(type) {
		case *string:
			*p = v
		case *bool:
			*p, err = strconv.ParseBool(v)
		case *int:
			*p, err = strconv.Atoi(v)
		default:
			err = fmt.Errorf("unsupported type %T", dst)
		}
		if err != nil {
			return fmt.Errorf("parse %s: %w", env, err)
		}
	}
	return nil
}
