package core

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"time"

	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"golang.org/x/sync/singleflight"
)

const StoryboardVTTFile = "storyboard.vtt"

const StoryboardPlanFile = "storyboard.json"

var spriteNameRe = regexp.MustCompile(`^storyboard-\d+\.jpg$`)

// StoryboardInput snapshots a storyboard request so builds outlive Managers.
type StoryboardInput struct {
	CacheDir     string
	FileID       int64
	Etag         string
	URL          string
	ServiceToken string
	Input        string
	Headers      string
	Duration     time.Duration
	FFmpeg       string
	LogID        string
}

// storyboardService builds storyboards process-wide, deduplicated by cache
// dir. A build keeps running if its Manager goes away mid-flight.
type storyboardService struct {
	sf singleflight.Group
}

var sharedStoryboards = &storyboardService{}

// storyboardSlots caps concurrent storyboard ffmpeg builds at NumCPU;
// extra builds queue behind it.
var storyboardSlots = make(chan struct{}, runtime.NumCPU())

// IsStoryboardLeaf reports whether a serve leaf is a storyboard file.
// Sprite names are strict so no user input ever reaches the filesystem.
func IsStoryboardLeaf(leaf string) bool {
	return leaf == StoryboardVTTFile || spriteNameRe.MatchString(leaf)
}

// storyboardFile is the stored storyboard payload, validated by Etag.
type storyboardFile struct {
	Etag string                `json:"etag"`
	Plan ffmpeg.StoryboardPlan `json:"plan"`
}

func (m *Manager) storyboardInput() StoryboardInput {
	return StoryboardInput{
		CacheDir:     m.c.CacheDir(),
		FileID:       m.fileid,
		Etag:         m.etag,
		URL:          m.url,
		ServiceToken: m.getServiceToken(),
		Input:        m.ffmpegInput(),
		Headers:      m.inputHeaders(),
		Duration:     m.probe.Duration,
		FFmpeg:       m.c.FFmpeg,
		LogID:        m.id,
	}
}

// ServeStoryboard builds the storyboard on first view and serves the VTT
// or sprite. The VTT is rendered per request with the query baked in.
func (m *Manager) ServeStoryboard(w http.ResponseWriter, r *http.Request, leaf, query string) {
	sharedStoryboards.Serve(w, r, m.storyboardInput(), leaf, query)
}

// EnsureStoryboard builds the storyboard once and returns its dir.
// The plan JSON is written last as the ready marker.
func (m *Manager) EnsureStoryboard() (string, error) {
	return sharedStoryboards.Ensure(m.storyboardInput())
}

// Serve builds the storyboard on first view and serves the VTT or sprite.
func (s *storyboardService) Serve(w http.ResponseWriter, r *http.Request, in StoryboardInput, leaf, query string) {
	if !IsStoryboardLeaf(leaf) {
		w.WriteHeader(http.StatusNotFound)
		return
	}
	dir, err := s.Ensure(in)
	if err != nil {
		log.Printf("%s: storyboard: %v", in.LogID, err)
		w.WriteHeader(http.StatusNotFound)
		return
	}
	w.Header().Set("Cache-Control", "max-age=86400, public")
	if leaf == StoryboardVTTFile {
		file, err := LoadStoryboardPlan(in.CacheDir, in.FileID, in.Etag)
		if err != nil {
			log.Printf("%s: storyboard: %v", in.LogID, err)
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "text/vtt")
		w.Write([]byte(file.Plan.VTT(query)))
		return
	}
	w.Header().Set("Content-Type", "image/jpeg")
	http.ServeFile(w, r, filepath.Join(dir, leaf))
}

// Ensure builds the storyboard once and returns its dir.
func (s *storyboardService) Ensure(in StoryboardInput) (string, error) {
	dir := FileCacheDir(in.CacheDir, in.FileID)
	if dir == "" {
		return "", os.ErrNotExist
	}
	if _, err := LoadStoryboardPlan(in.CacheDir, in.FileID, in.Etag); err == nil {
		return dir, nil
	}
	_, err, _ := s.sf.Do(dir, func() (any, error) {
		if _, err := LoadStoryboardPlan(in.CacheDir, in.FileID, in.Etag); err == nil {
			return nil, nil
		}
		return nil, s.build(dir, in)
	})
	if err != nil {
		return "", err
	}
	return dir, nil
}

// LoadStoryboardPlan reads the stored plan, evicting on etag mismatch.
// Missing plans surface as os.ErrNotExist.
func LoadStoryboardPlan(cacheDir string, fileid int64, etag string) (storyboardFile, error) {
	var file storyboardFile
	dir := FileCacheDir(cacheDir, fileid)
	if dir == "" {
		return file, os.ErrNotExist
	}
	data, err := os.ReadFile(filepath.Join(dir, StoryboardPlanFile))
	if err != nil {
		if os.IsNotExist(err) {
			return file, os.ErrNotExist
		}
		return file, err
	}
	if err := json.Unmarshal(data, &file); err != nil {
		EvictFileCache(cacheDir, fileid)
		return file, os.ErrNotExist
	}
	if file.Etag != etag {
		EvictFileCache(cacheDir, fileid)
		return file, os.ErrNotExist
	}
	return file, nil
}

func (s *storyboardService) build(dir string, in StoryboardInput) error {
	plan := ffmpeg.PlanStoryboard(in.Duration)
	start := time.Now()
	log.Printf("%s: building storyboard (%d thumbs @ %.1fs)", in.LogID, plan.Count, plan.Interval)

	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}
	// Render into same-dir .part files; the plan JSON goes last as the
	// ready marker.
	pattern := filepath.Join(dir, "storyboard-%d.jpg.part")
	for _, stale := range glob(filepath.Join(dir, "storyboard-*.jpg.part")) {
		os.Remove(stale)
	}
	select {
	case storyboardSlots <- struct{}{}:
	default:
		log.Printf("%s: storyboard queued behind %d builds", in.LogID, len(storyboardSlots))
		storyboardSlots <- struct{}{}
	}
	defer func() { <-storyboardSlots }()
	input, headers := in.Input, in.Headers
	if input == "" {
		input, headers = in.URL, HeadersBlock(in.ServiceToken)
	} else if input != in.URL {
		if _, err := os.Stat(input); err != nil {
			input, headers = in.URL, HeadersBlock(in.ServiceToken)
		}
	}
	if err := ffmpeg.BuildStoryboard(context.Background(), in.FFmpeg, input, headers, plan, pattern); err != nil {
		return err
	}
	log.Printf("%s: storyboard rendered in %s", in.LogID, time.Since(start).Round(time.Second))

	// Keep only the sprites actually produced and truncate dangling cues.
	parts := glob(filepath.Join(dir, "storyboard-*.jpg.part"))
	if len(parts) == 0 {
		return os.ErrNotExist
	}
	sort.Strings(parts)
	if max := len(parts) * plan.PerGrid; max < plan.Count {
		plan.Count = max
		plan.Sprites = len(parts)
	}
	for i, part := range parts {
		if err := os.Rename(part, filepath.Join(dir, ffmpeg.SpriteName(i))); err != nil {
			return err
		}
	}
	// Drop sprites from a stale longer plan, if any.
	for _, stale := range glob(filepath.Join(dir, "storyboard-*.jpg"))[len(parts):] {
		os.Remove(stale)
	}

	data, err := json.Marshal(storyboardFile{Etag: in.Etag, Plan: plan})
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, StoryboardPlanFile+".part"), data, 0644); err != nil {
		return err
	}
	return os.Rename(filepath.Join(dir, StoryboardPlanFile+".part"), filepath.Join(dir, StoryboardPlanFile))
}

func glob(pattern string) []string {
	matches, _ := filepath.Glob(pattern)
	return matches
}
