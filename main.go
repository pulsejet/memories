package main

import (
	"encoding/json"
	"fmt"
	"io/ioutil"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
)

type Handler struct {
	c        *Config
	managers map[string]*Manager
	mutex    sync.RWMutex
	close    chan string
}

func NewHandler(c *Config) *Handler {
	h := &Handler{
		c:        c,
		managers: make(map[string]*Manager),
		close:    make(chan string),
	}

	// Recreate tempdir
	os.RemoveAll(c.TempDir)
	os.MkdirAll(c.TempDir, 0755)

	go h.watchClose()
	return h
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
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

	// Print loaded config
	fmt.Printf("%+v\n", c)
}

func main() {
	if len(os.Args) >= 2 && os.Args[1] == "test" {
		fmt.Println("test successful")
		return
	}

	c := &Config{
		Bind:            ":47788",
		ChunkSize:       3,
		LookBehind:      3,
		GoalBufferMin:   1,
		GoalBufferMax:   4,
		StreamIdleTime:  60,
		ManagerIdleTime: 60,
	}

	// Load config file from second argument
	if len(os.Args) >= 2 {
		loadConfig(os.Args[1], c)
	} else {
		log.Fatal("Missing config file")
	}

	if c.FFmpeg == "" || c.FFprobe == "" || c.TempDir == "" {
		log.Fatal("Missing critical param -- check config file")
	}

	log.Println("Starting VOD server")

	h := NewHandler(c)
	http.Handle("/", h)
	http.ListenAndServe(c.Bind, nil)

	log.Println("Exiting VOD server")
	h.Close()
}
