package core

import (
	"net/url"
	"slices"
	"strings"
)

// ParsePlayableCodecs parses the "codecs" query param, e.g. "?codecs=h264,hevc".
// It returns nil when the param is absent or empty.
func ParsePlayableCodecs(query string) []string {
	values, err := url.ParseQuery(strings.TrimPrefix(query, "?"))
	if err != nil {
		return nil
	}
	if v := values.Get("codecs"); v != "" {
		return strings.Split(v, ",")
	}
	return nil
}

// IsCodecPlayable reports whether an ffprobe codec_name can be served directly.
func IsCodecPlayable(codec string, playableCodecs []string) bool {
	return codec == CODEC_H264 || slices.Contains(playableCodecs, codec)
}
