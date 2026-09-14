package ffmpeg

import (
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

func baseSpec() Spec {
	return Spec{
		Bin:       "ffmpeg",
		Input:     "/videos/input.mp4",
		ChunkSize: 3,
		QF:        25,
		FrameRate: 30,
		Quality:   "720p",
		Width:     1280,
		Height:    720,
	}
}

func cmd(s Spec, args []string) string {
	return QuoteForLog(append([]string{s.Bin}, args...))
}

func TestEncoder(t *testing.T) {
	require.Equal(t, EncoderX264, Encoder(Spec{}))
	require.Equal(t, EncoderVAAPI, Encoder(Spec{VAAPI: true}))
	require.Equal(t, EncoderNVENC, Encoder(Spec{NVENC: true}))
}

func TestBuildArgsSoftware(t *testing.T) {
	c := cmd(baseSpec(), BuildArgs(baseSpec()))
	require.Contains(t, c, `"-c:v" libx264 -preset faster -crf 25`)
	require.Contains(t, c, "scale=force_original_aspect_ratio=decrease:w=1280:h=1280")
	require.NotContains(t, c, "-hwaccel")
	require.NotContains(t, c, "-ss")
	require.NotContains(t, c, "-noautorotate")
	require.Contains(t, c, `-map "0:a:0?"`)
	require.Contains(t, c, `"-c:a" aac -ac 2 -ar 48000 "-b:a" 128k`)
}

func TestBuildArgsSeekAndMax(t *testing.T) {
	s := baseSpec()
	s.StartAt = 12.5
	require.Contains(t, cmd(s, BuildArgs(s)), "-ss 12.500000")

	max := baseSpec()
	max.Quality, max.Width, max.Height = QualityMax, 1920, 1080
	c := cmd(max, BuildArgs(max))
	require.NotContains(t, c, "w=1920")
}

func TestBuildArgsVAAPI(t *testing.T) {
	s := baseSpec()
	s.VAAPI, s.VAAPILowPower = true, true
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, "-hwaccel vaapi")
	require.Contains(t, c, "scale_vaapi=force_original_aspect_ratio=decrease:format=nv12")
	require.Contains(t, c, "-global_quality 25 -low_power 1")
}

func TestBuildArgsTranspose(t *testing.T) {
	s := baseSpec()
	s.VAAPI, s.UseTranspose, s.HLS, s.Rotation = true, true, true, -90
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, "-noautorotate")
	require.Contains(t, c, "transpose_vaapi=1")

	sw := baseSpec()
	sw.NVENC, sw.NVENCScale, sw.UseTranspose, sw.HLS, sw.Rotation = true, "cuda", true, true, 90
	require.Contains(t, cmd(sw, BuildArgs(sw)), "hwdownload,format=nv12,transpose=2")

	off := baseSpec()
	off.Rotation = 90
	require.NotContains(t, cmd(off, BuildArgs(off)), "transpose")
}

func TestBuildArgsNVENC(t *testing.T) {
	s := baseSpec()
	s.NVENC, s.NVENCScale, s.NVENCTemporalAQ = true, "cuda", true
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, "scale_cuda=force_original_aspect_ratio=decrease:passthrough=0")
	require.Contains(t, c, "-preset p6 -tune ll -rc vbr")
	require.Contains(t, c, "-temporal-aq 1")
}

func TestBuildArgsTonemap(t *testing.T) {
	sw := baseSpec()
	sw.HDR = true
	c := cmd(sw, BuildArgs(sw))
	require.Contains(t, c, "zscale=t=linear")
	require.Contains(t, c, "tonemap=hable")
	require.Contains(t, c, "format=yuv420p")
	require.NotContains(t, c, "format=nv12,scale=")

	vaapi := baseSpec()
	vaapi.HDR, vaapi.VAAPI = true, true
	c = cmd(vaapi, BuildArgs(vaapi))
	require.Contains(t, c, "hwdownload,zscale=")
	require.Contains(t, c, "tonemap=hable")
	require.Contains(t, c, "format=nv12,hwupload,scale_vaapi=")

	nvenc := baseSpec()
	nvenc.HDR, nvenc.NVENC, nvenc.NVENCScale = true, true, "cuda"
	c = cmd(nvenc, BuildArgs(nvenc))
	require.Contains(t, c, "hwdownload,zscale=")
	require.Contains(t, c, "tonemap=hable")
	require.Contains(t, c, "format=nv12,hwupload,scale_cuda=")

	sdr := baseSpec()
	require.NotContains(t, cmd(sdr, BuildArgs(sdr)), "tonemap")
	require.NotContains(t, cmd(sdr, BuildArgs(sdr)), "zscale")
}

func TestSegmentArgs(t *testing.T) {
	s := baseSpec()
	c := cmd(s, SegmentArgs(s, 4, SegmentPattern("/tmp/vod", "720p")))
	require.Contains(t, c, "-start_number 4")
	require.Contains(t, c, "-hls_segment_filename /tmp/vod/720p-%06d.ts")
	require.Contains(t, c, `"expr:gte(t,n_forced*3)" -`)

	gop := baseSpec()
	gop.UseGopSize = true
	g := cmd(gop, SegmentArgs(gop, 0, SegmentPattern("/tmp/vod", "720p")))
	require.Contains(t, g, "-g 90 -keyint_min 90")
	require.NotContains(t, g, "force_key_frames")
}

func TestMP4Args(t *testing.T) {
	c := cmd(baseSpec(), MP4Args(baseSpec()))
	require.Contains(t, c, `-movflags frag_keyframe+empty_moov+faststart -f mp4 "pipe:1"`)
}

func TestSegmentPaths(t *testing.T) {
	require.Equal(t, "/tmp/vod/720p-%06d.ts", SegmentPattern("/tmp/vod", "720p"))
	require.Equal(t, "/tmp/vod/720p-000003.ts", SegmentPath("/tmp/vod", "720p", 3))
}

func TestParseSegmentName(t *testing.T) {
	q, id, err := ParseSegmentName("720p-000003.ts")
	require.NoError(t, err)
	require.Equal(t, "720p", q)
	require.Equal(t, 3, id)

	q, id, err = ParseSegmentName("/tmp/go-vod/my-id-1/max-000042.ts")
	require.NoError(t, err)
	require.Equal(t, "max", q)
	require.Equal(t, 42, id)

	for _, bad := range []string{"", "720p.ts", "720p-abc.ts", "720p-000003.mp4", "a-b-c.ts"} {
		_, _, err := ParseSegmentName(bad)
		require.Error(t, err, bad)
	}
}

func TestParseSegmentLine(t *testing.T) {
	q, id, ok := ParseSegmentLine("  1080p-000003.ts\n")
	require.True(t, ok)
	require.Equal(t, "1080p", q)
	require.Equal(t, 3, id)

	_, _, ok = ParseSegmentLine("frame=  100 fps=30")
	require.False(t, ok)
}

func TestQuoteForLog(t *testing.T) {
	require.Equal(t, `-vf "scale=1:2"`, QuoteForLog([]string{"-vf", "scale=1:2"}))
}

func TestEncoderCopy(t *testing.T) {
	require.Equal(t, EncoderCopy, Encoder(Spec{Copy: true}))
	require.Equal(t, EncoderCopy, Encoder(Spec{Copy: true, VAAPI: true, NVENC: true}))
}

func TestBuildArgsCopy(t *testing.T) {
	s := baseSpec()
	s.Copy = true
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, `"-c:v" copy`)
	require.Contains(t, c, `"-c:a" aac`)
	require.NotContains(t, c, "-vf")
	require.NotContains(t, c, "-hwaccel")
	require.NotContains(t, c, "-crf")
}

func TestSegmentArgsCopy(t *testing.T) {
	s := baseSpec()
	s.Copy = true
	c := cmd(s, SegmentArgs(s, 2, SegmentPattern("/tmp/vod", "direct")))
	require.Contains(t, c, "-hls_time 3")
	require.NotContains(t, c, "force_key_frames")
	require.NotContains(t, c, " -g ")
	require.NotContains(t, c, "split_by_time")
}

func TestCopySegments(t *testing.T) {
	segs := CopySegments([]float64{0, 2, 4, 6, 8, 10}, 11*time.Second, 3)
	require.Equal(t, []Segment{
		{Start: 0, Duration: 4},
		{Start: 4, Duration: 4},
		{Start: 8, Duration: 3},
	}, segs)

	segs = CopySegments([]float64{0, 10}, 12*time.Second, 3)
	require.Equal(t, []Segment{
		{Start: 0, Duration: 10},
		{Start: 10, Duration: 2},
	}, segs)

	segs = CopySegments([]float64{8, 0, 4, 2, 10, 6}, 11*time.Second, 3)
	require.Len(t, segs, 3)

	segs = CopySegments([]float64{0, 12}, 12*time.Second, 3)
	require.Equal(t, []Segment{{Start: 0, Duration: 12}}, segs)

	require.Nil(t, CopySegments(nil, 11*time.Second, 3))
	require.Nil(t, CopySegments([]float64{0}, 0, 3))
	require.Nil(t, CopySegments([]float64{0}, 11*time.Second, 0))
}
