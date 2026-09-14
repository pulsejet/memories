package ffmpeg

import (
	"testing"

	"github.com/stretchr/testify/require"
)

func TestParseFrameRate(t *testing.T) {
	require.Equal(t, 30, parseFrameRate("30/1"))
	require.Equal(t, 29, parseFrameRate("30000/1001"))
	require.Equal(t, 30, parseFrameRate("0/0"))
	require.Equal(t, 30, parseFrameRate("bogus"))
	require.Equal(t, 30, parseFrameRate(""))
}
