package core

import (
	"log"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"

	"github.com/pulsejet/memories/go-vod/config"
	"golang.org/x/sync/singleflight"
)

type Registry struct {
	cfg     *config.Config
	idle    chan IdleEvent
	mu      sync.Mutex
	members map[string]*Manager
	gen     atomic.Uint64
	sf      singleflight.Group
}

type IdleEvent struct {
	ID         string
	Generation uint64
}

// ManagerParams identifies a manager for a file and client.
type ManagerParams struct {
	Path           string
	StreamID       string
	FileID         int64
	Etag           string
	PlayableCodecs []string
	TConfig        config.TCfg
}

func NewRegistry(cfg *config.Config, idle chan IdleEvent) *Registry {
	return &Registry{
		cfg:     cfg,
		idle:    idle,
		members: make(map[string]*Manager),
	}
}

func (r *Registry) GetOrCreate(p ManagerParams) (*Manager, error) {
	if m := r.get(p); m != nil {
		return m, nil
	}

	v, err, _ := r.sf.Do(p.StreamID+"\x00"+p.Path, func() (any, error) {
		if m := r.get(p); m != nil {
			return m, nil
		}
		return r.create(p)
	})
	if err != nil {
		return nil, err
	}
	return v.(*Manager), nil
}

func (r *Registry) get(p ManagerParams) *Manager {
	r.mu.Lock()
	defer r.mu.Unlock()

	m := r.members[p.StreamID]
	if m == nil || m.path != p.Path {
		return nil
	}
	if p.Etag != "" && m.etag != "" && m.etag != p.Etag {
		return nil
	}
	return m
}

func (r *Registry) create(p ManagerParams) (*Manager, error) {
	manager, err := NewManager(NewManagerArgs{
		ManagerParams: p,
		C:             r.cfg,
		Generation:    r.gen.Add(1),
		Idle:          r.idle,
	})
	if err != nil {
		log.Println("Error creating manager", err)
		freeIfTemp(r.cfg.TempDir, p.Path)
		return nil, err
	}

	r.mu.Lock()
	defer r.mu.Unlock()

	if old := r.members[p.StreamID]; old != nil {
		old.Destroy()
	}
	r.members[p.StreamID] = manager
	return manager, nil
}

func (r *Registry) Remove(streamID string, generation uint64) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if m := r.members[streamID]; m == nil || m.generation != generation {
		return
	}
	delete(r.members, streamID)
}

func freeIfTemp(tempDir, path string) {
	if filepath.Dir(path) == filepath.Clean(tempDir) && strings.Contains(path, "-govod-temp-") {
		os.Remove(path)
	}
}
