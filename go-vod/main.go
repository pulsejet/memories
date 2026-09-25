package main

import (
	"fmt"
	"log"
	"os"

	"github.com/pulsejet/memories/go-vod/api"
	"github.com/pulsejet/memories/go-vod/config"
)

const VERSION = "0.6.0"

func main() {
	c := config.Defaults(VERSION)

	for _, arg := range os.Args[1:] {
		switch arg {
		case "-version-monitor":
			c.VersionMonitor = true
		case "-version":
			fmt.Print("go-vod " + VERSION)
			return
		default:
			if err := c.LoadFile(arg); err != nil {
				log.Fatal("Error loading config: ", err)
			}
		}
	}

	if err := c.AutoDetect(); err != nil {
		log.Fatal("Error detecting environment: ", err)
	}

	if err := c.Validate(); err != nil {
		log.Fatal("Invalid config: ", err)
	}

	code := api.NewServer(c).Start()

	log.Println("Exiting go-vod with status code", code)
	os.Exit(code)
}
