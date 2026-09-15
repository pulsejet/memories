package core

import (
	"encoding/json"
	"os"
	"path/filepath"
)

const EtagHeader = "X-Go-Vod-Etag"

const KeyframeCacheFile = "keyframes.json"

func CacheFileDir(cacheDir, etag string) string {
	if cacheDir == "" || len(etag) < 4 {
		return ""
	}
	return filepath.Join(cacheDir, etag[:2], etag[2:4], etag)
}

func KeyframeCachePath(cacheDir, etag string) string {
	dir := CacheFileDir(cacheDir, etag)
	if dir == "" {
		return ""
	}
	return filepath.Join(dir, KeyframeCacheFile)
}

func LoadCachedKeyframes(cacheDir, etag string) ([]float64, bool) {
	path := KeyframeCachePath(cacheDir, etag)
	if path == "" {
		return nil, false
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, false
	}
	var out []float64
	if err := json.Unmarshal(data, &out); err != nil {
		return nil, false
	}
	return out, true
}

func StoreCachedKeyframes(cacheDir, etag string, keys []float64) error {
	path := KeyframeCachePath(cacheDir, etag)
	if path == "" {
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	data, err := json.Marshal(keys)
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0644)
}
