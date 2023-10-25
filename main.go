package main

import (
	"fmt"
	"log"
	"os"

	"github.com/pulsejet/go-vod/go_vod"
)

const VERSION = "0.1.23"

func main() {
	// Build initial configuration
	c := &go_vod.Config{
		VersionMonitor:  false,
		Version:         VERSION,
		Bind:            ":47788",
		ChunkSize:       3,
		LookBehind:      3,
		GoalBufferMin:   1,
		GoalBufferMax:   4,
		StreamIdleTime:  60,
		ManagerIdleTime: 60,
	}

	// Parse arguments
	for _, arg := range os.Args[1:] {
		if arg == "-version-monitor" {
			c.VersionMonitor = true
		} else if arg == "-version" {
			fmt.Print("go-vod " + VERSION)
			return
		} else {
			c.FromFile(arg) // config file
		}
	}

	// Auto detect ffmpeg and ffprobe
	c.AutoDetect()

	// Start server
	code := go_vod.NewHandler(c).Start()

	// Exit
	log.Println("Exiting go-vod with status code", code)
	os.Exit(code)
}
