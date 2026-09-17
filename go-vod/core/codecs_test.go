package core

import (
	"testing"

	"github.com/stretchr/testify/require"
)

func TestParseCodecs(t *testing.T) {
	for _, tc := range []struct {
		codecs string
		want   []string
	}{
		{"", nil},
		{"bogus", []string{"bogus"}},
		{"h264", []string{"h264"}},
		{"h264,hevc", []string{"h264", "hevc"}},
		{"h264,h264", []string{"h264", "h264"}},
	} {
		require.Equal(t, tc.want, ParseCodecs(tc.codecs), tc.codecs)
	}
}

func TestIsCodecPlayable(t *testing.T) {
	require.True(t, IsCodecPlayable("h264", nil))
	require.False(t, IsCodecPlayable("hevc", nil))

	playable := []string{"hevc"}
	require.True(t, IsCodecPlayable("h264", playable))
	require.True(t, IsCodecPlayable("hevc", playable))
	require.False(t, IsCodecPlayable("vp9", playable))
}
