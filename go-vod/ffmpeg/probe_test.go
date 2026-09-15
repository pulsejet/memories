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

func TestParseProbeJSONHDR(t *testing.T) {
	probe := func(stream string) bool {
		info, err := ParseProbeJSON([]byte(`{"streams":[` + stream + `]}`))
		require.NoError(t, err)
		return info.HDR
	}

	require.True(t, probe(`{"codec_type":"video","codec_name":"hevc","width":3840,"height":2160,`+
		`"pix_fmt":"yuv420p10le","color_space":"bt2020nc",`+
		`"color_transfer":"smpte2084","color_primaries":"bt2020"}`))
	require.True(t, probe(`{"codec_type":"video","codec_name":"hevc","pix_fmt":"yuv420p10le",`+
		`"color_transfer":"arib-std-b67"}`))
	require.True(t, probe(`{"codec_type":"video","codec_name":"hevc","pix_fmt":"p010le",`+
		`"color_space":"bt2020nc","color_primaries":"bt2020"}`))
	require.False(t, probe(`{"codec_type":"video","codec_name":"h264","pix_fmt":"yuv420p10le",`+
		`"color_space":"bt709","color_transfer":"bt709"}`))
	require.False(t, probe(`{"codec_type":"video","codec_name":"h264","pix_fmt":"yuv420p"}`))
}

func TestParseProbeJSONAudio(t *testing.T) {
	withAudio, err := ParseProbeJSON([]byte(`{"streams":[` +
		`{"codec_type":"video","codec_name":"h264","width":64,"height":64},` +
		`{"codec_type":"audio","codec_name":"aac","channels":2,` +
		`"sample_rate":"48000","bit_rate":"128000"}]}`))
	require.NoError(t, err)
	require.Equal(t, "h264", withAudio.CodecName)
	require.Equal(t, AudioInfo{CodecName: "aac", Channels: 2, SampleRate: 48000, BitRate: 128000}, withAudio.Audio)

	silent, err := ParseProbeJSON([]byte(`{"streams":[` +
		`{"codec_type":"video","codec_name":"h264","width":64,"height":64}]}`))
	require.NoError(t, err)
	require.Equal(t, AudioInfo{}, silent.Audio)

	_, err = ParseProbeJSON([]byte(`{"streams":[` +
		`{"codec_type":"audio","codec_name":"aac"}]}`))
	require.Error(t, err)
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
