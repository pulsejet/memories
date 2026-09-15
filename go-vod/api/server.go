package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/core"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
)

type Server struct {
	cfg      *config.Config
	cfgMu    sync.RWMutex
	server   *http.Server
	reg      *core.Registry
	idle     chan core.IdleEvent
	exitCode int
}

func NewServer(cfg *config.Config) *Server {
	os.RemoveAll(cfg.TempDir)
	os.MkdirAll(cfg.TempDir, 0755)
	os.MkdirAll(cfg.ResolvedCacheDir(), 0755)

	s := &Server{cfg: cfg, idle: make(chan core.IdleEvent)}
	s.reg = core.NewRegistry(cfg, s.idle)
	return s
}

func (s *Server) routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /vod", s.handleVod)
	mux.HandleFunc("POST /config", s.handleConfig)
	mux.HandleFunc("POST /create", s.handleCreate)
	return mux
}

func (s *Server) configured() bool {
	s.cfgMu.RLock()
	defer s.cfgMu.RUnlock()
	return s.cfg.Configured
}

func (s *Server) chunkSize() int {
	s.cfgMu.RLock()
	defer s.cfgMu.RUnlock()
	return s.cfg.ChunkSize
}

func (s *Server) tempDir() string {
	s.cfgMu.RLock()
	defer s.cfgMu.RUnlock()
	return s.cfg.TempDir
}

func (s *Server) maxUpload() int64 {
	s.cfgMu.RLock()
	defer s.cfgMu.RUnlock()
	return s.cfg.MaxUploadSize
}

// VodRequest is the envelope for every file request from PHP. The cache
// is keyed by FileID; Etag is stored in every plan and a mismatch evicts
// the file. Query carries the "?..." passthrough baked into playlists.
type VodRequest struct {
	Client  string `json:"client"`
	FileID  int64  `json:"fileid"`
	Etag    string `json:"etag"`
	Path    string `json:"path"`
	Profile string `json:"profile"`
	Query   string `json:"query"`
}

func (s *Server) handleVod(w http.ResponseWriter, r *http.Request) {
	if s.cfg.VersionMonitor && !s.versionOk(w, r) {
		return
	}

	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 1<<20))
	if err != nil {
		log.Println("Error reading vod body", err)
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	var req VodRequest
	if err := json.Unmarshal(body, &req); err != nil {
		log.Println("Error unmarshaling vod request", err)
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	if req.Client == "" || req.Path == "" || req.Profile == "" {
		log.Println("Invalid vod request", req.Profile)
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	s.serve(w, r, req)
}

func (s *Server) serve(w http.ResponseWriter, r *http.Request, req VodRequest) {
	leaf := req.Profile

	if leaf == "test" {
		w.Header().Set("Content-Type", "application/json")

		size := 0
		if info, err := os.Stat(req.Path); err == nil {
			size = int(info.Size())
		}

		json.NewEncoder(w).Encode(map[string]any{
			"version": s.cfg.Version,
			"size":    size,
		})
		return
	}

	if !s.configured() {
		w.WriteHeader(http.StatusServiceUnavailable)
		return
	}

	if !validProfile(leaf) {
		w.WriteHeader(http.StatusNotFound)
		return
	}

	manager, err := s.reg.GetOrCreate(req.Path, req.Client, req.FileID, req.Etag)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	query := req.Query
	if query != "" && !strings.HasPrefix(query, "?") {
		query = "?" + query
	}
	switch {
	case leaf == "ignore":
		// Warm up the manager without serving anything
	case leaf == "index.m3u8":
		body, err := MasterPlaylist(manager.Renditions(), manager.FrameRate(), query)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		WriteM3U8(w, body)
	case strings.HasSuffix(leaf, ".m3u8"):
		quality := strings.TrimSuffix(leaf, ".m3u8")
		if !manager.HasStream(quality) {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		body, err := VariantPlaylist(manager, quality, s.chunkSize(), query)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		WriteM3U8(w, body)
	case strings.HasSuffix(leaf, ".ts"):
		quality, id, err := ffmpeg.ParseSegmentName(leaf)
		if err != nil {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		if !manager.ServeChunk(w, quality, id) {
			w.WriteHeader(http.StatusNotFound)
		}
	case strings.HasSuffix(leaf, ".mp4"):
		manager.ServeFullVideo(w, r, strings.TrimSuffix(leaf, ".mp4"))
	case core.IsStoryboardLeaf(leaf):
		manager.ServeStoryboard(w, r, leaf, query)
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

// validProfile gates manager creation on known profile names.
func validProfile(leaf string) bool {
	switch {
	case leaf == "ignore" || leaf == "index.m3u8":
		return true
	case strings.HasSuffix(leaf, ".m3u8"),
		strings.HasSuffix(leaf, ".ts"),
		strings.HasSuffix(leaf, ".mp4"):
		return true
	case core.IsStoryboardLeaf(leaf):
		return true
	}
	return false
}

func (s *Server) handleConfig(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	body, err := io.ReadAll(r.Body)
	if err != nil {
		log.Println("Error reading body", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	s.cfgMu.Lock()
	defer s.cfgMu.Unlock()

	next := *s.cfg
	if err := json.Unmarshal(body, &next); err != nil {
		log.Println("Error unmarshaling config", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}
	if v, ok := os.LookupEnv("CACHE_DIR"); ok && v != "" {
		next.CacheDir = v
	}
	if err := next.Validate(); err != nil {
		log.Println("Error validating config", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	next.Configured = true
	*s.cfg = next
	os.MkdirAll(next.ResolvedCacheDir(), 0755)
	log.Printf("%+v\n", s.cfg)
}

func (s *Server) handleCreate(w http.ResponseWriter, r *http.Request) {
	file, err := os.CreateTemp(s.tempDir(), "govod-temp-")
	if err != nil {
		log.Println("Error creating temp file", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}
	defer file.Close()

	if _, err := io.Copy(file, http.MaxBytesReader(w, r.Body, s.maxUpload())); err != nil {
		file.Close()
		os.Remove(file.Name())
		if _, ok := errors.AsType[*http.MaxBytesError](err); ok {
			log.Println("Upload exceeds size limit", file.Name())
			w.WriteHeader(http.StatusRequestEntityTooLarge)
		} else {
			log.Println("Error writing to temp file", err)
			w.WriteHeader(http.StatusInternalServerError)
		}
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"path": file.Name()})
}

func (s *Server) versionOk(w http.ResponseWriter, r *http.Request) bool {
	expected := r.Header.Get("X-Go-Vod-Version")
	if len(expected) > 0 && expected != s.cfg.Version {
		log.Println("Version mismatch", expected, s.cfg.Version)

		w.WriteHeader(http.StatusServiceUnavailable)

		s.exitCode = 12
		s.Close()
		return false
	}

	return true
}

func (s *Server) Start() int {
	log.Println("Starting go-vod " + s.cfg.Version + " on " + s.cfg.Bind)
	s.server = &http.Server{Addr: s.cfg.Bind, Handler: s.routes()}

	go func() {
		err := s.server.ListenAndServe()
		if err == http.ErrServerClosed {
			log.Println("HTTP server closed")
		} else if err != nil {
			log.Fatal("Error starting server: ", err)
		}
	}()

	for {
		ev := <-s.idle
		if ev.ID == "" {
			break
		}
		s.reg.Remove(ev.ID, ev.Generation)
	}

	log.Println("Shutting down HTTP server")
	ctx, cancel := context.WithDeadline(context.TODO(), time.Now().Add(5*time.Second))
	defer cancel()
	s.server.Shutdown(ctx)

	return s.exitCode
}

func (s *Server) Close() {
	s.idle <- core.IdleEvent{}
}
