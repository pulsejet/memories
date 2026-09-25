package core

// ServiceTokenHeader carries the short-lived Nextcloud provisioned token.
const ServiceTokenHeader = "X-Memories-Service-Token"

// StreamOriginalHeader signals PHP to stream the original file itself.
const StreamOriginalHeader = "X-Go-Vod-Original"

// HeadersBlock renders the token as ffmpeg "-headers" content.
func HeadersBlock(serviceToken string) string {
	if serviceToken == "" {
		return ""
	}
	return ServiceTokenHeader + ": " + serviceToken + "\r\n"
}
