package core

import (
	"net/http"
	"time"
)

// ServiceTokenHeader carries the short-lived Nextcloud provisioned token.
const ServiceTokenHeader = "X-Memories-Service-Token"

// StreamOriginalHeader signals PHP to stream the original file itself.
const StreamOriginalHeader = "X-Go-Vod-Original"

// UpstreamClient fetches file bytes back from Nextcloud with a hard timeout.
var UpstreamClient = &http.Client{Timeout: 10 * time.Second}

// MaxTempDownloadSize caps a single live-extract download into a temp file.
const MaxTempDownloadSize = 4 << 30 // 4GB

// HeadersBlock renders the token as ffmpeg "-headers" content.
func HeadersBlock(serviceToken string) string {
	if serviceToken == "" {
		return ""
	}
	return ServiceTokenHeader + ": " + serviceToken + "\r\n"
}
