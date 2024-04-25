package transcoder

import (
	"encoding/json"
	"io/ioutil"
	"log"
	"os"
	"os/exec"
)

type Config struct {
	// Current version of go-vod
	Version string

	// Is this server configured?
	Configured bool

	// Restart the server if incorrect version detected
	VersionMonitor bool

	// Bind address
	Bind string `json:"bind"`

	// FFmpeg binary
	FFmpeg string `json:"ffmpeg"`
	// FFprobe binary
	FFprobe string `json:"ffprobe"`
	// Temp files directory
	TempDir string `json:"tempdir"`

	// Size of each chunk in seconds
	ChunkSize int `json:"chunkSize"`
	// How many *chunks* to look behind before restarting transcoding
	LookBehind int `json:"lookBehind"`
	// Number of chunks in goal to restart encoding
	GoalBufferMin int `json:"goalBufferMin"`
	// Number of chunks in goal to stop encoding
	GoalBufferMax int `json:"goalBufferMax"`

	// Number of seconds to wait before shutting down encoding
	StreamIdleTime int `json:"streamIdleTime"`
	// Number of seconds to wait before shutting down a client
	ManagerIdleTime int `json:"managerIdleTime"`

	// Quality Factor (e.g. CRF / global_quality)
	QF int `json:"qf"`

	// Hardware acceleration configuration

	// VA-API
	VAAPI         bool `json:"vaapi"`
	VAAPILowPower bool `json:"vaapiLowPower"`

	// NVENC
	NVENC           bool   `json:"nvenc"`
	NVENCTemporalAQ bool   `json:"nvencTemporalAQ"`
	NVENCScale      string `json:"nvencScale"` // cuda, npp

	// Use transpose workaround for streaming (VA-API)
	UseTranspose bool `json:"useTranspose"`
	// Force tranpose in software
	ForceSwTranspose bool `json:"forceSwTranspose"`

	// Use GOP size workaround for streaming (NVENC)
	UseGopSize bool `json:"useGopSize"`
}

func (c *Config) FromFile(path string) {
	// load json config
	content, err := ioutil.ReadFile(path)
	if err != nil {
		log.Fatal("Error when opening file: ", err)
	}

	err = json.Unmarshal(content, &c)
	if err != nil {
		log.Fatal("Error loading config file", err)
	}

	// Set config as loaded
	c.Configured = true
	c.Print()
}

func (c *Config) AutoDetect() {
	// Auto-detect ffmpeg and ffprobe paths
	if c.FFmpeg == "" || c.FFprobe == "" {
		ffmpeg, err := exec.LookPath("ffmpeg")
		if err != nil {
			log.Fatal("Could not find ffmpeg")
		}

		ffprobe, err := exec.LookPath("ffprobe")
		if err != nil {
			log.Fatal("Could not find ffprobe")
		}

		c.FFmpeg = ffmpeg
		c.FFprobe = ffprobe
	}

	// Auto-choose tempdir
	if c.TempDir == "" {
		c.TempDir = os.TempDir() + "/go-vod"
	}

	// Print updated config
	c.Print()
}

func (c *Config) Print() {
	log.Printf("%+v\n", c)
}
