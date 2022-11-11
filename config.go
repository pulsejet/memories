package main

type Config struct {
	// FFmpeg binary
	ffmpeg string
	// FFprobe binary
	ffprobe string

	// Size of each chunk in seconds
	chunkSize int
	// How many *chunks* to look behind before restarting transcoding
	lookBehind int
	// Number of chunks in goal to restart encoding
	goalBufferMin int
	// Number of chunks in goal to stop encoding
	goalBufferMax int
}
