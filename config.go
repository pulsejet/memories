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
	// How many chunks to buffer ahead of player position
	goalBuffer int
}
