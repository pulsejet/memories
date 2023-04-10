package main

type Config struct {
	// Is this server configured?
	Configured bool

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

	// Hardware acceleration configuration

	// VA-API
	VAAPI         bool `json:"vaapi"`
	VAAPILowPower bool `json:"vaapiLowPower"`

	// NVENC
	NVENC           bool   `json:"nvenc"`
	NVENCTemporalAQ bool   `json:"nvencTemporalAQ"`
	NVENCScale      string `json:"nvencScale"` // cuda, npp
}
