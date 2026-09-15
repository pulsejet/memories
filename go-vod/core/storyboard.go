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

// ServeStoryboard builds the storyboard on first view (cached per etag
// after that) and serves the requested VTT or sprite with a long cache.
// The VTT is rendered per request with the query string baked into sprite
// URLs (share tokens, like m3u8 playlists), so only the plan is stored.
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
		plan, err := LoadStoryboardPlan(dir)
		if err != nil {
			log.Printf("%s: storyboard: %v", m.id, err)
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "text/vtt")
		w.Write([]byte(plan.VTT(query)))
		return
	}
	w.Header().Set("Content-Type", "image/jpeg")
	http.ServeFile(w, r, filepath.Join(dir, leaf))
}

// EnsureStoryboard renders sprites + plan into the etag cache dir,
// or returns the existing dir. The plan JSON is written last as the
// ready marker.
func (m *Manager) EnsureStoryboard() (string, error) {
	dir := CacheFileDir(m.c.ResolvedCacheDir(), m.etag)
	if dir == "" {
		return "", os.ErrNotExist
	}
	if _, err := os.Stat(filepath.Join(dir, StoryboardPlanFile)); err == nil {
		return dir, nil
	}
	_, err, _ := storyboardSF.Do(dir, func() (any, error) {
		if _, err := os.Stat(filepath.Join(dir, StoryboardPlanFile)); err == nil {
			return nil, nil
		}
		return nil, m.buildStoryboard(dir)
	})
	if err != nil {
		return "", err
	}
	return dir, nil
}

// LoadStoryboardPlan reads a built storyboard's stored plan.
func LoadStoryboardPlan(dir string) (ffmpeg.StoryboardPlan, error) {
	var plan ffmpeg.StoryboardPlan
	data, err := os.ReadFile(filepath.Join(dir, StoryboardPlanFile))
	if err != nil {
		return plan, err
	}
	if err := json.Unmarshal(data, &plan); err != nil {
		return plan, err
	}
	return plan, nil
}

func (m *Manager) buildStoryboard(dir string) error {
	plan := ffmpeg.PlanStoryboard(m.probe.Duration)
	log.Printf("%s: building storyboard (%d thumbs @ %.1fs)", m.id, plan.Count, plan.Interval)

	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}
	// Render into same-dir .part files so a crash never leaves a
	// half-written sprite behind; VTT goes last as the ready marker.
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

	data, err := json.Marshal(plan)
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
