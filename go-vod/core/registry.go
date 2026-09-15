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

func NewRegistry(cfg *config.Config, idle chan IdleEvent) *Registry {
	return &Registry{
		cfg:     cfg,
		idle:    idle,
		members: make(map[string]*Manager),
	}
}

func (r *Registry) GetOrCreate(path, streamID string, fileid int64, etag string) (*Manager, error) {
	if m := r.get(path, streamID, etag); m != nil {
		return m, nil
	}

	v, err, _ := r.sf.Do(streamID+"\x00"+path, func() (any, error) {
		if m := r.get(path, streamID, etag); m != nil {
			return m, nil
		}
		return r.create(path, streamID, fileid, etag)
	})
	if err != nil {
		return nil, err
	}
	return v.(*Manager), nil
}

func (r *Registry) get(path, streamID, etag string) *Manager {
	r.mu.Lock()
	defer r.mu.Unlock()

	m := r.members[streamID]
	if m == nil || m.path != path {
		return nil
	}
	if etag != "" && m.etag != "" && m.etag != etag {
		return nil
	}
	return m
}

func (r *Registry) create(path, streamID string, fileid int64, etag string) (*Manager, error) {
	manager, err := NewManager(r.cfg, path, streamID, fileid, etag, r.gen.Add(1), r.idle)
	if err != nil {
		log.Println("Error creating manager", err)
		freeIfTemp(r.cfg.TempDir, path)
		return nil, err
	}

	r.mu.Lock()
	defer r.mu.Unlock()

	if old := r.members[streamID]; old != nil {
		old.Destroy()
	}
	r.members[streamID] = manager
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
