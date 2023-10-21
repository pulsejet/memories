package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
)

const VERSION = "0.1.19"

func main() {
	// Build initial configuration
	c := &Config{
		VersionMonitor:  false,
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

	// Build HTTP server
	log.Println("Starting go-vod " + VERSION + " on " + c.Bind)
	handler := NewHandler(c)
	handler.server = &http.Server{Addr: c.Bind, Handler: handler}

	// Start server and wait for handler exit
	handler.Start()
	log.Println("Exiting VOD server")

	// Exit with status code
	os.Exit(handler.exitCode)
}
