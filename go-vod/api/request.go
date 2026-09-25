package api

import (
	"net/url"

	"github.com/pulsejet/memories/go-vod/config"
)

// VodRequest is the envelope for every file request from PHP. ServiceToken
// is a short-lived provisioned token go-vod sends back upstream. go-vod
// builds the Nextcloud URL from FileID. The cache is keyed by FileID;
// Etag is stored in every plan and a mismatch evicts the file.
// Query carries the passthrough baked into playlists.
type VodRequest struct {
	// Client is a unique identifier for the caller.
	Client string `json:"client"`
	// FileID is the unique identifier.
	FileID int64 `json:"fileid"`
	// Etag is the file change detection tag.
	Etag string `json:"etag"`
	// ServiceToken is the auth token for calling back to PHP.
	ServiceToken string `json:"serviceToken"`
	// Profile is the transcoding profile for this request.
	Profile string `json:"profile"`
	// Query are passthrough parameters, e.g. auth tokens.
	Query VodQuery `json:"query"`
	// TConfig is the config for this request.
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
