package core

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"

	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"golang.org/x/sync/singleflight"
)

const StoryboardVTTFile = "storyboard.vtt"

const StoryboardPlanFile = "storyboard.json"

var spriteNameRe = regexp.MustCompile(`^storyboard-\d+\.jpg$`)

var storyboardSF singleflight.Group

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

// ServeStoryboard builds the storyboard on first view and serves the VTT
// or sprite. The VTT is rendered per request with the query baked in.
func (m *Manager) ServeStoryboard(w http.ResponseWriter, r *http.Request, leaf, query string) {
	if !IsStoryboardLeaf(leaf) {
		w.WriteHeader(http.StatusNotFound)
		return
	}
	dir, err := m.EnsureStoryboard()
	if err != nil {
		log.Printf("%s: storyboard: %v", m.id, err)
		w.WriteHeader(http.StatusNotFound)
		return
	}
	w.Header().Set("Cache-Control", "max-age=86400, public")
	if leaf == StoryboardVTTFile {
		file, err := LoadStoryboardPlan(m.c.ResolvedCacheDir(), m.fileid, m.etag)
		if err != nil {
			log.Printf("%s: storyboard: %v", m.id, err)
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

// EnsureStoryboard builds the storyboard once and returns its dir.
// The plan JSON is written last as the ready marker.
func (m *Manager) EnsureStoryboard() (string, error) {
	dir := FileCacheDir(m.c.ResolvedCacheDir(), m.fileid)
	if dir == "" {
		return "", os.ErrNotExist
	}
	if _, err := LoadStoryboardPlan(m.c.ResolvedCacheDir(), m.fileid, m.etag); err == nil {
		return dir, nil
	}
	_, err, _ := storyboardSF.Do(dir, func() (any, error) {
		if _, err := LoadStoryboardPlan(m.c.ResolvedCacheDir(), m.fileid, m.etag); err == nil {
			return nil, nil
		}
		return nil, m.buildStoryboard(dir)
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

func (m *Manager) buildStoryboard(dir string) error {
	plan := ffmpeg.PlanStoryboard(m.probe.Duration)
	log.Printf("%s: building storyboard (%d thumbs @ %.1fs)", m.id, plan.Count, plan.Interval)

	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}
	// Render into same-dir .part files; the plan JSON goes last as the
	// ready marker.
	pattern := filepath.Join(dir, "storyboard-%d.jpg.part")
	for _, stale := range glob(filepath.Join(dir, "storyboard-*.jpg.part")) {
		os.Remove(stale)
	}
	if err := ffmpeg.BuildStoryboard(context.Background(), m.c.FFmpeg, m.path, plan, pattern); err != nil {
		return err
	}

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

	data, err := json.Marshal(storyboardFile{Etag: m.etag, Plan: plan})
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
