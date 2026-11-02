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

// fmp4Codecs are the ffprobe codec_names stream-copied into fMP4 segments.
var fmp4Codecs = []string{CODEC_H264, "hevc", "av1", "vp9"}

// IsCodecFMP4 reports whether HLS can stream-copy the codec into fMP4.
func IsCodecFMP4(codec string) bool {
	return slices.Contains(fmp4Codecs, codec)
}

// IsCodecPlayable reports whether an ffprobe codec_name can be served directly.
func IsCodecPlayable(codec string, playableCodecs []string) bool {
	return codec == CODEC_H264 || slices.Contains(playableCodecs, codec)
}
