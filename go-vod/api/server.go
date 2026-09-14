package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"os"
	"path"
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

	s := &Server{cfg: cfg, idle: make(chan core.IdleEvent)}
	s.reg = core.NewRegistry(cfg, s.idle)
	return s
}

func (s *Server) routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /", s.handleGet)
	mux.HandleFunc("POST /", s.handlePost)
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

// File paths arrive whole-encoded (slashes as %2F, see BinExt::getGoVodUrl),
// so routes cannot be expressed as ServeMux segment patterns.
// Split the decoded path in this one place instead. The path is cleaned
// first, so equivalent spellings (doubled slashes from the leading %2F,
// trailing slashes, dot segments) all resolve to one canonical
// sid/dir/leaf triple — and therefore one manager — per file.
func splitPath(p string) (sid, dir, leaf string, ok bool) {
	parts := strings.Split(path.Clean("/"+p), "/")
	if len(parts) < 4 {
		return "", "", "", false
	}
	return parts[1], "/" + strings.Join(parts[2:len(parts)-1], "/"), parts[len(parts)-1], true
}

func (s *Server) handleGet(w http.ResponseWriter, r *http.Request) {
	if s.cfg.VersionMonitor && !s.versionOk(w, r) {
		return
	}

	sid, dir, leaf, ok := splitPath(r.URL.Path)
	if !ok {
		log.Println("Invalid URL", r.URL.Path)
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	s.serve(w, r, sid, dir, leaf)
}

func (s *Server) handlePost(w http.ResponseWriter, r *http.Request) {
	if s.cfg.VersionMonitor && !s.versionOk(w, r) {
		return
	}

	sid, dir, leaf, ok := splitPath(r.URL.Path)
	if !ok {
		log.Println("Invalid URL", r.URL.Path)
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	if dir == "/create" || strings.HasPrefix(dir, "/create/") {
		s.handleCreate(w, r, sid)
		return
	}
	if leaf == "config" {
		s.handleConfig(w, r)
		return
	}
	s.serve(w, r, sid, dir, leaf)
}

func (s *Server) serve(w http.ResponseWriter, r *http.Request, sid, dir, leaf string) {
	if leaf == "test" {
		w.Header().Set("Content-Type", "application/json")

		size := 0
		if info, err := os.Stat(dir); err == nil {
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

	manager, err := s.reg.GetOrCreate(dir, sid)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	query := QueryString(r)
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
	default:
		w.WriteHeader(http.StatusNotFound)
	}
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
	if err := next.Validate(); err != nil {
		log.Println("Error validating config", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	next.Configured = true
	*s.cfg = next
	log.Printf("%+v\n", s.cfg)
}

func (s *Server) handleCreate(w http.ResponseWriter, r *http.Request, sid string) {
	file, err := os.CreateTemp(s.tempDir(), sid+"-govod-temp-")
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
