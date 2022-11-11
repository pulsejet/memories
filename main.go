package main

import (
	"fmt"
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
	os.RemoveAll(c.tempdir)
	os.MkdirAll(c.tempdir, 0755)

	go h.watchClose()
	return h
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	url := r.URL.Path
	parts := make([]string, 0)

	for _, part := range strings.Split(url, "/") {
		if part != "" {
			parts = append(parts, part)
		}
	}

	if len(parts) < 3 {
		log.Println("Invalid URL", url)
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	streamid := parts[0]
	path := "/" + strings.Join(parts[1:len(parts)-1], "/")
	chunk := parts[len(parts)-1]

	// log.Println("Serving", path, streamid, chunk)

	if streamid == "" || chunk == "" || path == "" {
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	manager := h.getManager(path, streamid)
	if manager == nil {
		manager = h.createManager(path, streamid)
	}

	if manager == nil {
		w.WriteHeader(http.StatusInternalServerError)
		return
	}
	manager.ServeHTTP(w, r, chunk)
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

func main() {
	if len(os.Args) >= 2 && os.Args[1] == "test" {
		fmt.Println("test successful")
		return
	}

	log.Println("Starting VOD server")

	h := NewHandler(&Config{
		ffmpeg:          "ffmpeg",
		ffprobe:         "ffprobe",
		tempdir:         "/tmp/go-vod",
		chunkSize:       3,
		lookBehind:      5,
		goalBufferMin:   3,
		goalBufferMax:   8,
		streamIdleTime:  120,
		managerIdleTime: 240,
	})

	http.Handle("/", h)
	http.ListenAndServe(":47788", nil)

	log.Println("Exiting VOD server")
	h.Close()
}
