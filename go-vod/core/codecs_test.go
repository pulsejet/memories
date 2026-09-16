package core

import (
	"testing"

	"github.com/stretchr/testify/require"
)

func TestParsePlayableCodecs(t *testing.T) {
	for _, tc := range []struct {
		query string
		want  []string
	}{
		{"", nil},
		{"?", nil},
		{"%zz", nil},
		{"?token=abc", nil},
		{"?codecs=", nil},
		{"?codecs=bogus", []string{"bogus"}},
		{"?codecs=h264", []string{"h264"}},
		{"codecs=h264,hevc", []string{"h264", "hevc"}},
		{"?codecs=h264%2Chevc&token=abc", []string{"h264", "hevc"}},
		{"?codecs=h264,h264", []string{"h264", "h264"}},
	} {
		require.Equal(t, tc.want, ParsePlayableCodecs(tc.query), tc.query)
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
