package ffmpeg

import (
	"context"
	"log"
	"strings"
)

// remoteInputArgs are the options for an authenticated http input: one
// persistent seekable connection with bounded reconnects. Empty for local
// inputs (no headers).
func remoteInputArgs(headers string) []string {
	if headers == "" {
		return nil
	}
	args := []string{
		"-multiple_requests", "1",
		"-seekable", "1",
	}
	args = append(args, reconnectArgs...)
	return append(args, "-headers", headers)
}

// reconnectArgs are the http input reconnect flags shared by transcode and
// storyboard inputs: transient network/server failures are ridden out inside
// ffmpeg instead of failing the job. Retries stay bounded (count and total
// delay) so a dead upstream still surfaces as an error. Only 429 and 5xx
// reconnect; other 4xx (bad token, missing file) fail fast. No
// reconnect_at_eof: inputs are finite files, so clean EOF must end the job
// instead of looping reconnects at the last byte.
var reconnectArgs = []string{
	"-reconnect", "1",
	"-reconnect_on_network_error", "1",
	"-reconnect_on_http_error", "429,5xx",
	"-reconnect_streamed", "1",
	"-reconnect_delay_max", "5",
	"-reconnect_max_retries", "10",
	"-reconnect_delay_total_max", "30",
	"-respect_retry_after", "1",
}

// DetectHTTPOptions drops reconnectArgs the installed ffprobe doesn't support
// (e.g. reconnect_max_retries needs ffmpeg 7.1); older builds reject unknown
// options outright. Call once at startup.
func DetectHTTPOptions(ffprobe string) {
	out, err := Command(context.Background(), ffprobe, "-hide_banner", "-h", "protocol=http").Output()
	if err != nil {
		log.Printf("Failed to detect ffprobe http options: %v", err)
		return
	}
	var supported []string
	for i := 0; i < len(reconnectArgs); i += 2 {
		if strings.Contains(string(out), reconnectArgs[i]+" ") {
			supported = append(supported, reconnectArgs[i], reconnectArgs[i+1])
		} else {
			log.Printf("ffprobe does not support http option %s, skipping", reconnectArgs[i])
		}
	}
	reconnectArgs = supported
	log.Printf("Using http reconnect args: %s", strings.Join(supported, " "))
}
