package core

import (
	"slices"
	"strings"
)

// ParseCodecs splits the "codecs" value, e.g. "h264,hevc".
// It returns nil when the value is absent or empty.
func ParseCodecs(codecs string) []string {
	if codecs == "" {
		return nil
	}
	return strings.Split(codecs, ",")
}

// IsCodecPlayable reports whether an ffprobe codec_name can be served directly.
func IsCodecPlayable(codec string, playableCodecs []string) bool {
	return codec == CODEC_H264 || slices.Contains(playableCodecs, codec)
}
