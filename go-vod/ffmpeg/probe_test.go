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

func TestParseKeyframes(t *testing.T) {
	require.Equal(t,
		[]float64{0, 2.5},
		ParseKeyframes([]byte("0.000000,K__\n0.016683,___\n2.500000,K__\n")),
	)
	// Missing timestamps are skipped, not fatal
	require.Equal(t,
		[]float64{1.5},
		ParseKeyframes([]byte("N/A,___\n\n1.500000,K__\n")),
	)
	// Packets carrying side data gain trailing empty CSV fields
	require.Equal(t,
		[]float64{0},
		ParseKeyframes([]byte("0.000000,K__,\n")),
	)
	require.Empty(t, ParseKeyframes([]byte("")))
	// Probe JSON is not a keyframe grid
	require.Empty(t, ParseKeyframes([]byte(`{"streams":[]}`)))
}
