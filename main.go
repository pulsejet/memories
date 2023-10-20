package main

import (
	"encoding/json"
	"fmt"
	"io/ioutil"
	"log"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"sync"
)

const (
	VERSION = "0.1.19"
)

type Handler struct {
	c        *Config
	managers map[string]*Manager
	mutex    sync.RWMutex
	close    chan string
	server   *http.Server
	exitCode int
}

func NewHandler(c *Config) *Handler {
	h := &Handler{
		c:        c,
		managers: make(map[string]*Manager),
		close:    make(chan string),
		exitCode: 0,
	}

	// Recreate tempdir
	os.RemoveAll(c.TempDir)
	os.MkdirAll(c.TempDir, 0755)

	return h
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	url := r.URL.Path
	parts := make([]string, 0)

	// log.Println("Serving", url)

	// Check version if monitoring is enabled
	if h.c.VersionMonitor {
		expected := r.Header.Get("X-Go-Vod-Version")
		if len(expected) > 0 && expected != VERSION {
			log.Println("Version mismatch", expected, VERSION)

			// Try again in some time
			w.WriteHeader(http.StatusServiceUnavailable)

			// Exit with status code 65
			h.exitCode = 12
			h.Close()
			return
		}
	}

	// Break url into parts
	for _, part := range strings.Split(url, "/") {
		if part != "" {
			parts = append(parts, part)
		}
	}

	// Serve actual file from manager
	if len(parts) < 3 {
		log.Println("Invalid URL", url)
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	// Get streamid and chunk
	streamid := parts[0]
	path := "/" + strings.Join(parts[1:len(parts)-1], "/")
	chunk := parts[len(parts)-1]

	// Check if POST request to create temp file
	if r.Method == "POST" && len(parts) >= 2 && parts[1] == "create" {
		var err error
		path, err = h.createTempFile(w, r, parts)
		if err != nil {
			return
		}
	}

	// Check if test request
	if chunk == "test" {
		w.Header().Set("Content-Type", "application/json")

		// check if test file is readable
		size := 0
		info, err := os.Stat(path)
		if err == nil {
			size = int(info.Size())
		}

		json.NewEncoder(w).Encode(map[string]interface{}{
			"version": VERSION,
			"size":    size,
		})
		return
	}

	// Check if configuration request
	if r.Method == "POST" && chunk == "config" {
		w.Header().Set("Content-Type", "application/json")
		// read new config
		body, err := ioutil.ReadAll(r.Body)
		if err != nil {
			log.Println("Error reading body", err)
			w.WriteHeader(http.StatusInternalServerError)
			return
		}

		// Unmarshal config
		if err := json.Unmarshal(body, h.c); err != nil {
			log.Println("Error unmarshaling config", err)
			w.WriteHeader(http.StatusInternalServerError)
			return
		}

		// Set config as loaded
		h.c.Configured = true

		// Print loaded config
		log.Printf("%+v\n", h.c)
		return
	}

	// Check if configured
	if !h.c.Configured {
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}

	// Check if valid
	if streamid == "" || path == "" {
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	// Get existing manager or create new one
	manager := h.getManager(path, streamid)
	if manager == nil {
		manager = h.createManager(path, streamid)
	}

	// Failed to create manager
	if manager == nil {
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	// Serve chunk if asked for
	if chunk != "" && chunk != "ignore" {
		manager.ServeHTTP(w, r, chunk)
	}
}

func (h *Handler) getManager(path string, streamid string) *Manager {
	h.mutex.RLock()
	defer h.mutex.RUnlock()

	m := h.managers[streamid]
	if m == nil || m.path != path {
		return nil
	}
	return m
}

func (h *Handler) createManager(path string, streamid string) *Manager {
	manager, err := NewManager(h.c, path, streamid, h.close)
	if err != nil {
		log.Println("Error creating manager", err)
		freeIfTemp(path)
		return nil
	}

	h.mutex.Lock()
	defer h.mutex.Unlock()

	old := h.managers[streamid]
	if old != nil {
		old.Destroy()
	}

	h.managers[streamid] = manager
	return manager
}

func (h *Handler) removeManager(streamid string) {
	h.mutex.Lock()
	defer h.mutex.Unlock()
	delete(h.managers, streamid)
}

func (h *Handler) watchClose() {
	for {
		id := <-h.close
		if id == "" {
			return
		}
		h.removeManager(id)
	}
}

func (h *Handler) Close() {
	h.close <- ""
}

func loadConfig(path string, c *Config) {
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

	// Print loaded config
	log.Printf("%+v\n", c)
}

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
			// Config file
			loadConfig(arg, c)
		}
	}

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

	// Print config
	log.Printf("%+v\n", c)

	// Start server
	log.Println("Starting go-vod " + VERSION + " on " + c.Bind)
	handler := NewHandler(c)
	server := &http.Server{Addr: c.Bind, Handler: handler}
	handler.server = server

	// Start listening on different thread
	go func() {
		err := server.ListenAndServe()
		if err != nil {
			log.Fatal("Error starting server", err)
		}
	}()

	// Wait for handler exit
	handler.watchClose()
	log.Println("Exiting VOD server")

	// Exit with status code
	os.Exit(handler.exitCode)
}
