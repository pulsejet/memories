package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/core"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
)

type Server struct {
	cfg      *config.Config
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
	mux.HandleFunc("GET /health", s.handleHealth)
	mux.HandleFunc("POST /vod", s.handleVod)
	return mux
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{
		"status":  "ok",
		"version": s.cfg.Version,
	})
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
	if req.Client == "" || req.FileID <= 0 || req.Profile == "" {
		log.Println("Invalid vod request", req.Profile)
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	if err := req.TConfig.Validate(); err != nil {
		log.Println("Invalid vod config", err)
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	s.serve(w, r, req)
}

func (s *Server) serve(w http.ResponseWriter, r *http.Request, req VodRequest) {
	leaf := req.Profile
	fileURL := s.cfg.FileURL(req.FileID)

	if leaf == "test" {
		w.Header().Set("Content-Type", "application/json")

		json.NewEncoder(w).Encode(map[string]any{
			"version": s.cfg.Version,
			"size":    s.headSize(fileURL, req.ServiceToken),
		})
		return
	}

	if !validProfile(leaf) {
		w.WriteHeader(http.StatusNotFound)
		return
	}

	manager, err := s.reg.GetOrCreate(core.ManagerParams{
		URL:            fileURL,
		StreamID:       req.Client,
		FileID:         req.FileID,
		Etag:           req.Etag,
		ServiceToken:   req.ServiceToken,
		PlayableCodecs: core.ParseCodecs(req.Query.Codecs),
		TConfig:        req.TConfig,
	})
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	query := req.Query.Encode()
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
		body, err := VariantPlaylist(manager, req.TConfig, quality, query)
		if errors.Is(err, core.ErrCopyPending) {
			w.WriteHeader(http.StatusConflict)
			return
		}
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

// headSize probes the upstream file size with a HEAD carrying the
// provisioned token. Zero when unreachable or rejected.
func (s *Server) headSize(fileURL, serviceToken string) int {
	req, err := http.NewRequest("HEAD", fileURL, nil)
	if err != nil {
		log.Println("Error creating test request", err)
		return 0
	}
	if serviceToken != "" {
		req.Header.Set(core.ServiceTokenHeader, serviceToken)
	}

	res, err := http.DefaultClient.Do(req)
	if err != nil {
		log.Println("Error testing upstream URL", err)
		return 0
	}
	defer res.Body.Close()
	if res.StatusCode == http.StatusForbidden {
		log.Println("Upstream service token rejected; Nextcloud must provision a fresh one")
		return 0
	}
	if res.StatusCode != http.StatusOK {
		return 0
	}
	return int(res.ContentLength)
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

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, os.Interrupt, syscall.SIGTERM)
	defer signal.Stop(sig)
	go func() {
		<-sig
		s.Close()
	}()

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
