package core

import (
	"encoding/binary"
	"encoding/json"
	"fmt"
	"hash/fnv"
	"os"
	"path/filepath"
)

const KeyframeCacheFile = "keyframes.json"

const ProbeCacheFile = "probe.json"

const probeCacheVersion = 2

// FileCacheDir is the file's cache home, sharded by hashed fileid.
// Empty when caching is unavailable (no cache dir or fileid).
func FileCacheDir(cacheDir string, fileid int64) string {
	if cacheDir == "" || fileid <= 0 {
		return ""
	}
	h := fnv.New32a()
	var b [8]byte
	binary.LittleEndian.PutUint64(b[:], uint64(fileid))
	h.Write(b[:])
	return filepath.Join(cacheDir, fmt.Sprintf("%02x", h.Sum32()&0xff), fmt.Sprint(fileid))
}

// EvictFileCache drops the entire cache for one file on etag mismatch.
func EvictFileCache(cacheDir string, fileid int64) {
	if dir := FileCacheDir(cacheDir, fileid); dir != "" {
		os.RemoveAll(dir)
	}
}

func KeyframeCachePath(cacheDir string, fileid int64) string {
	dir := FileCacheDir(cacheDir, fileid)
	if dir == "" {
		return ""
	}
	return filepath.Join(dir, KeyframeCacheFile)
}

// keyframesPlan is the stored keyframe payload, validated by Etag.
type keyframesPlan struct {
	Etag string    `json:"etag"`
	Keys []float64 `json:"keys"`
}

func LoadCachedKeyframes(cacheDir string, fileid int64, etag string) ([]float64, bool) {
	path := KeyframeCachePath(cacheDir, fileid)
	if path == "" {
		return nil, false
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, false
	}
	var plan keyframesPlan
	if err := json.Unmarshal(data, &plan); err != nil {
		EvictFileCache(cacheDir, fileid)
		return nil, false
	}
	if plan.Etag != etag {
		EvictFileCache(cacheDir, fileid)
		return nil, false
	}
	return plan.Keys, true
}

func StoreCachedKeyframes(cacheDir string, fileid int64, etag string, keys []float64) error {
	path := KeyframeCachePath(cacheDir, fileid)
	if path == "" {
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	data, err := json.Marshal(keyframesPlan{Etag: etag, Keys: keys})
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0644)
}

func ProbeCachePath(cacheDir string, fileid int64) string {
	dir := FileCacheDir(cacheDir, fileid)
	if dir == "" {
		return ""
	}
	return filepath.Join(dir, ProbeCacheFile)
}

// probePlan is the stored base-probe payload, validated by Etag.
// Only ProbeVideoData is cached; copyEligible is re-derived per Manager
// from probe + playableCodecs.
type probePlan struct {
	V     int            `json:"v"`
	Etag  string         `json:"etag"`
	Probe ProbeVideoData `json:"probe"`
}

func LoadCachedProbe(cacheDir string, fileid int64, etag string) (*ProbeVideoData, bool) {
	path := ProbeCachePath(cacheDir, fileid)
	if path == "" {
		return nil, false
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, false
	}
	var plan probePlan
	if err := json.Unmarshal(data, &plan); err != nil {
		EvictFileCache(cacheDir, fileid)
		return nil, false
	}
	if plan.V != probeCacheVersion || plan.Etag != etag {
		EvictFileCache(cacheDir, fileid)
		return nil, false
	}
	probe := plan.Probe
	return &probe, true
}

func StoreCachedProbe(cacheDir string, fileid int64, etag string, probe *ProbeVideoData) error {
	path := ProbeCachePath(cacheDir, fileid)
	if path == "" || probe == nil {
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	data, err := json.Marshal(probePlan{
		V:     probeCacheVersion,
		Etag:  etag,
		Probe: *probe,
	})
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0644)
}
