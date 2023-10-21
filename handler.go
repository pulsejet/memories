package main

import (
	"encoding/json"
	"io/ioutil"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
)

type Handler struct {
	c        *Config
	server   *http.Server
	managers map[string]*Manager
	mutex    sync.RWMutex
	close    chan string
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
	// Check version if monitoring is enabled
	if h.c.VersionMonitor && !h.versionOk(w, r) {
		return
	}

	url := r.URL.Path
	parts := make([]string, 0)

	// log.Println("Serving", url)

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

func (h *Handler) versionOk(w http.ResponseWriter, r *http.Request) bool {
	expected := r.Header.Get("X-Go-Vod-Version")
	if len(expected) > 0 && expected != VERSION {
		log.Println("Version mismatch", expected, VERSION)

		// Try again in some time
		w.WriteHeader(http.StatusServiceUnavailable)

		// Exit with status code 12
		h.exitCode = 12
		h.Close()
		return false
	}

	return true
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

func (h *Handler) Start() {
	go func() {
		err := h.server.ListenAndServe()
		if err != nil {
			log.Fatal("Error starting server", err)
		}
	}()

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
