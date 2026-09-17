package api

import (
	"net/url"

	"github.com/pulsejet/memories/go-vod/config"
)

// VodRequest is the envelope for every file request from PHP. The cache
// is keyed by FileID; Etag is stored in every plan and a mismatch evicts
// the file. Query carries the passthrough baked into playlists.
type VodRequest struct {
	Client  string      `json:"client"`
	FileID  int64       `json:"fileid"`
	Etag    string      `json:"etag"`
	Path    string      `json:"path"`
	Profile string      `json:"profile"`
	Query   VodQuery    `json:"query"`
	TConfig config.TCfg `json:"config"`
}

type VodQuery struct {
	Albums string `json:"albums,omitempty"`
	Token  string `json:"token,omitempty"`
	Codecs string `json:"codecs,omitempty"`
}

// Encode renders "?..." with only the auth fields.
// Codecs are server-side only and never baked into URLs.
func (q VodQuery) Encode() string {
	v := url.Values{}
	if q.Albums != "" {
		v.Set("albums", q.Albums)
	}
	if q.Token != "" {
		v.Set("token", q.Token)
	}
	if s := v.Encode(); s != "" {
		return "?" + s
	}
	return ""
}
