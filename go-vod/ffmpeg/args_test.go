package ffmpeg

import (
	"fmt"
	"slices"
	"strings"
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
		Audio:     AudioInfo{CodecName: "ac3", Channels: 6, SampleRate: 48000, BitRate: 384000},
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
	require.Contains(t, c, `-vf "format=yuv420p,scale=force_original_aspect_ratio=decrease:force_divisible_by=2:w=1280:h=1280"`)
	require.NotContains(t, c, "-hwaccel")
	require.NotContains(t, c, "-ss")
	require.NotContains(t, c, "-noautorotate")
	require.NotContains(t, c, "-headers")
	require.NotContains(t, c, "-reconnect")
	require.Contains(t, c, `-map "0:a:0?"`)
	require.Contains(t, c, `"-c:a" aac -ac 2 -ar 48000 "-b:a" 128k`)
}

func TestBuildArgsHeaders(t *testing.T) {
	s := baseSpec()
	s.Input = "http://nc/apps/memories/api/stream/7"
	s.Headers = "Authorization: Basic dXNlcjp0b2tlbg==\r\n"
	args := BuildArgs(s)
	require.Equal(t, s.Headers, args[slices.Index(args, "-headers")+1])
	require.Less(t, slices.Index(args, "-headers"), slices.Index(args, "-i"))
	require.Equal(t, s.Input, args[slices.Index(args, "-i")+1])
	require.Equal(t, "1", args[slices.Index(args, "-multiple_requests")+1])
	require.Equal(t, "1", args[slices.Index(args, "-seekable")+1])
	require.Less(t, slices.Index(args, "-multiple_requests"), slices.Index(args, "-i"))
	for flag, value := range map[string]string{
		"-reconnect":                  "1",
		"-reconnect_on_network_error": "1", "-reconnect_on_http_error": "429,5xx",
		"-reconnect_streamed": "1", "-reconnect_delay_max": "5",
		"-reconnect_max_retries": "10", "-reconnect_delay_total_max": "30",
		"-respect_retry_after": "1",
	} {
		require.Equal(t, value, args[slices.Index(args, flag)+1], flag)
		require.Less(t, slices.Index(args, flag), slices.Index(args, "-i"), flag)
	}
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
	require.Contains(t, c, "scale_vaapi=force_original_aspect_ratio=decrease:force_divisible_by=2:format=nv12")
	require.Contains(t, c, "-global_quality 25 -low_power 1")
	require.Contains(t, c, "vaapi=memories:/dev/dri/renderD128")

	dev := baseSpec()
	dev.VAAPI, dev.VAAPIDevice = true, "/dev/dri/renderD129"
	c = cmd(dev, BuildArgs(dev))
	require.Contains(t, c, "-hwaccel_device /dev/dri/renderD129")
	require.Contains(t, c, "vaapi=memories:/dev/dri/renderD129")
	require.NotContains(t, c, "renderD128")
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

func TestMP4Transpose(t *testing.T) {
	sw := baseSpec()
	sw.HLS, sw.UseTranspose, sw.Rotation = false, true, 90
	c := cmd(sw, MP4Args(sw))
	require.Contains(t, c, "-noautorotate")
	require.Contains(t, c, "transpose=2")

	vaapi := baseSpec()
	vaapi.HLS, vaapi.VAAPI, vaapi.UseTranspose, vaapi.Rotation = false, true, true, -90
	require.Contains(t, cmd(vaapi, MP4Args(vaapi)), "transpose_vaapi=1")

	off := baseSpec()
	off.HLS, off.Rotation = false, 90
	require.NotContains(t, cmd(off, MP4Args(off)), "transpose")
}

func TestBuildArgsNVENC(t *testing.T) {
	s := baseSpec()
	s.NVENC, s.NVENCScale, s.NVENCTemporalAQ = true, "cuda", true
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, "scale_cuda=force_original_aspect_ratio=decrease:force_divisible_by=2:format=nv12:passthrough=0")
	require.Contains(t, c, "-preset p6 -tune ll -rc vbr")
	require.Contains(t, c, "-temporal-aq 1")
	require.Contains(t, c, "-forced-idr 1")

	// 10-bit SDR converts in the scaler instead of reaching h264_nvenc.
	for _, scale := range []string{"cuda", "npp"} {
		deep := baseSpec()
		deep.BitDepth, deep.NVENC, deep.NVENCScale = 10, true, scale
		require.Contains(t, cmd(deep, BuildArgs(deep)), "scale_"+scale+"=force_original_aspect_ratio=decrease:force_divisible_by=2:format=nv12")
	}
}

func TestBuildArgsEvenDimensions(t *testing.T) {
	// Scaled sizes must stay even; odd sizes (e.g. 854x641) break encoders (#1208).
	vaapi, hdr, cl, nvenc := baseSpec(), baseSpec(), baseSpec(), baseSpec()
	vaapi.VAAPI = true
	hdr.HDR, hdr.BitDepth, hdr.VAAPI = true, 10, true
	cl.HDR, cl.VAAPI, cl.VAAPIOpenCL = true, true, true
	nvenc.NVENC, nvenc.NVENCScale = true, "cuda"
	for _, s := range []Spec{baseSpec(), vaapi, hdr, cl, nvenc} {
		require.Contains(t, cmd(s, BuildArgs(s)), "force_divisible_by=2")
	}
}

func TestBuildArgsTonemap(t *testing.T) {
	sw := baseSpec()
	sw.HDR = true
	c := cmd(sw, BuildArgs(sw))
	require.Contains(t, c, "scale=force_original_aspect_ratio=decrease:force_divisible_by=2:w=1280:h=1280,zscale=t=linear")
	require.Contains(t, c, "tonemap=hable:desat=0,")
	require.Contains(t, c, "zscale=t=bt709:m=bt709:range=tv,format=nv12")
	require.NotContains(t, c, "format=nv12,scale=")

	vaapi := baseSpec()
	vaapi.HDR, vaapi.VAAPI = true, true
	c = cmd(vaapi, BuildArgs(vaapi))
	require.Contains(t, c, `-vf "scale=force_original_aspect_ratio=decrease:force_divisible_by=2:w=1280:h=1280,zscale=`)
	require.NotContains(t, c, "hwdownload")
	require.Contains(t, c, "tonemap=hable:desat=0,")
	require.Contains(t, c, "format=nv12|vaapi,hwupload")
	require.NotContains(t, c, "hwupload,scale_vaapi=")

	nvenc := baseSpec()
	nvenc.HDR, nvenc.NVENC, nvenc.NVENCScale = true, true, "cuda"
	c = cmd(nvenc, BuildArgs(nvenc))
	require.Contains(t, c, `-vf "scale=force_original_aspect_ratio=decrease:force_divisible_by=2:w=1280:h=1280,zscale=`)
	require.NotContains(t, c, "hwdownload")
	require.Contains(t, c, "tonemap=hable:desat=0,")
	require.Contains(t, c, "format=nv12|cuda,hwupload")
	require.NotContains(t, c, "hwupload,scale_cuda=")

	sdr := baseSpec()
	require.NotContains(t, cmd(sdr, BuildArgs(sdr)), "tonemap")
	require.NotContains(t, cmd(sdr, BuildArgs(sdr)), "zscale")
}

func TestBuildArgsTonemapDepth(t *testing.T) {
	// CPU tonemapping takes system frames straight from the decoder, so no
	// hwdownload (which fails on software-decoded frames) and no depth pin.
	hdrs := map[string]Spec{}
	for _, scale := range []string{"cuda", "npp"} {
		for _, depth := range []int{8, 10, 12} {
			s := baseSpec()
			s.HDR, s.BitDepth, s.NVENC, s.NVENCScale = true, depth, true, scale
			hdrs[fmt.Sprintf("nvenc-%s-%d", scale, depth)] = s
		}
	}
	for _, depth := range []int{8, 10} {
		s := baseSpec()
		s.HDR, s.BitDepth, s.VAAPI = true, depth, true
		hdrs[fmt.Sprintf("vaapi-%d", depth)] = s
	}
	for name, s := range hdrs {
		c := cmd(s, BuildArgs(s))
		require.NotContains(t, c, "hwdownload", name)
		require.NotContains(t, c, "hwaccel_output_format", name)
		require.Contains(t, c, `-vf "scale=force_original_aspect_ratio=decrease:force_divisible_by=2:w=1280:h=1280,zscale=`, name)
		require.Regexp(t, `format=nv12\|(cuda|vaapi),hwupload"`, c, name)
	}

	// GPU tonemapping keeps hardware frames.
	gpu := baseSpec()
	gpu.HDR, gpu.BitDepth, gpu.NVENC, gpu.NVENCScale, gpu.NVENCCUDA = true, 10, true, "cuda", true
	require.Contains(t, cmd(gpu, BuildArgs(gpu)), "-hwaccel_output_format cuda")

	// Software transpose after tonemapping stays on the CPU: no
	// upload/download roundtrip around rotation.
	transposed := baseSpec()
	transposed.HDR, transposed.BitDepth, transposed.VAAPI = true, 10, true
	transposed.UseTranspose, transposed.ForceSwTranspose, transposed.Rotation = true, true, 90
	transposedCmd := cmd(transposed, BuildArgs(transposed))
	require.Contains(t, transposedCmd, "format=nv12,transpose=2,format=nv12|vaapi,hwupload")
	require.NotContains(t, transposedCmd, "hwupload,hwdownload")

	// Deep SDR is converted to nv12 by the hardware scaler, so the
	// transpose download must pin nv12, not the source depth.
	for _, depth := range []int{10, 12} {
		sdr := baseSpec()
		sdr.BitDepth, sdr.NVENC, sdr.NVENCScale = depth, true, "cuda"
		sdr.UseTranspose, sdr.Rotation = true, 90
		require.Contains(t, cmd(sdr, BuildArgs(sdr)), "format=nv12:passthrough=0:w=1280:h=1280,hwdownload,format=nv12,transpose=2")

		vaapi := baseSpec()
		vaapi.BitDepth, vaapi.VAAPI = depth, true
		vaapi.UseTranspose, vaapi.ForceSwTranspose, vaapi.Rotation = true, true, 90
		require.Contains(t, cmd(vaapi, BuildArgs(vaapi)), "format=nv12:w=1280:h=1280,hwdownload,format=nv12,transpose=2")
	}
}

func TestBuildArgsNoAudio(t *testing.T) {
	s := baseSpec()
	s.Audio = AudioInfo{}
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, `"-c:v" libx264`)
	require.NotContains(t, c, `-map "0:a`)
	require.NotContains(t, c, `"-c:a"`)
	require.NotContains(t, c, "b:a")
}

func TestBuildArgsVAAPIOpenCL(t *testing.T) {
	for _, quality := range []string{"720p", QualityMax} {
		for _, rotation := range []int{0, -90, 90, 180} {
			s := baseSpec()
			s.VAAPI, s.HDR, s.VAAPIOpenCL, s.UseTranspose = true, true, true, true
			s.Quality, s.Rotation, s.VAAPIDevice = quality, rotation, "/dev/dri/renderD129"
			args := BuildArgs(s)
			require.Contains(t, args, "vaapi=memories:/dev/dri/renderD129")
			require.Contains(t, args, "opencl=memories_opencl@memories")
			require.Equal(t, "memories_opencl", args[slices.Index(args, "-filter_hw_device")+1])
			filter := args[slices.Index(args, "-vf")+1]
			scale := "scale_vaapi=force_original_aspect_ratio=decrease:force_divisible_by=2:format=p010"
			if quality != QualityMax {
				scale += ":w=1280:h=1280"
			}
			want := scale + ",hwmap=derive_device=opencl," +
				"tonemap_opencl=tonemap=hable:desat=0:format=nv12:primaries=bt709:transfer=bt709:matrix=bt709:range=tv," +
				"hwmap=derive_device=vaapi:reverse=1"
			switch rotation {
			case -90:
				want += ",transpose_vaapi=1"
			case 90:
				want += ",transpose_vaapi=2"
			case 180:
				want += ",transpose_vaapi=1,transpose_vaapi=1"
			}
			require.Equal(t, want, filter)
			require.NotContains(t, strings.Join(args, " "), "hwdownload")
			require.NotContains(t, filter, "zscale")
		}
	}
}

func TestBuildArgsNVENCCUDA(t *testing.T) {
	for _, scaler := range []string{"cuda", "npp"} {
		for _, quality := range []string{"720p", QualityMax} {
			for _, rotation := range []int{0, -90, 90, 180, -180} {
				for _, forceSw := range []bool{false, true} {
					s := baseSpec()
					s.NVENC, s.HDR, s.NVENCCUDA, s.UseTranspose = true, true, true, true
					s.NVENCScale, s.Quality, s.Rotation, s.BitDepth = scaler, quality, rotation, 10
					s.ForceSwTranspose = forceSw
					args := BuildArgs(s)
					filter := args[slices.Index(args, "-vf")+1]
					want := "scale_" + scaler + "=force_original_aspect_ratio=decrease:force_divisible_by=2:format=p010le"
					if scaler == "cuda" {
						want += ":passthrough=0"
					}
					if quality != QualityMax {
						want += ":w=1280:h=1280"
					}
					want += ",tonemap_cuda=tonemap=hable:desat=0:format=nv12:primaries=bt709:transfer=bt709:matrix=bt709:range=tv"
					if rotation != 0 {
						transposer := "transpose_npp"
						software := forceSw || scaler == "cuda"
						if software {
							want += ",hwdownload,format=nv12"
							transposer = "transpose"
						}
						switch rotation {
						case -90:
							want += "," + transposer + "=1"
						case 90:
							want += "," + transposer + "=2"
						case 180, -180:
							want += "," + transposer + "=1," + transposer + "=1"
						}
						if software {
							want += ",format=nv12|cuda,hwupload"
						}
					}
					require.Equal(t, want, filter)
					require.Equal(t, "memories", args[slices.Index(args, "-filter_hw_device")+1])
					require.Equal(t, EncoderNVENC, args[slices.Index(args, "-c:v")+1])
					require.NotContains(t, filter, "zscale")
				}
			}
		}
	}

	for _, kind := range []string{"sdr", "software", "vaapi", "copy"} {
		t.Run(kind, func(t *testing.T) {
			s := baseSpec()
			s.NVENC, s.HDR, s.NVENCCUDA, s.NVENCScale = true, true, true, "cuda"
			switch kind {
			case "sdr":
				s.HDR = false
			case "software":
				s.NVENC = false
			case "vaapi":
				s.VAAPI = true
			case "copy":
				s.Copy = true
			}
			require.NotContains(t, strings.Join(BuildArgs(s), " "), "tonemap_cuda")
		})
	}
}

func TestBuildArgsAudioCopy(t *testing.T) {
	s := baseSpec()
	s.Audio = AudioInfo{CodecName: "aac", Channels: 2, SampleRate: 48000, BitRate: 128000}
	c := cmd(s, BuildArgs(s))
	require.Contains(t, c, `-map "0:a:0?" "-c:a" copy`)
	require.NotContains(t, c, "b:a")

	multi := baseSpec()
	multi.Audio = AudioInfo{CodecName: "aac", Channels: 6}
	require.Contains(t, cmd(multi, BuildArgs(multi)), `"-c:a" aac`)
}

func TestSegmentArgs(t *testing.T) {
	s := baseSpec()
	c := cmd(s, SegmentArgs(s, 4, SegmentPattern("/tmp/vod", "720p"), InitPath("/tmp/vod", "720p", 2)))
	require.Contains(t, c, "-fps_mode passthrough -bf 0")
	require.Contains(t, c, "-start_number 4")
	require.Contains(t, c, "-hls_segment_type fmp4")
	require.Contains(t, c, "-hls_fmp4_init_filename /tmp/vod/720p-init-2.m4s")
	require.Contains(t, c, `-hls_segment_options "movflags=+frag_discont+negative_cts_offsets:use_editlist=0"`)
	require.Contains(t, c, "-hls_segment_filename /tmp/vod/720p-%06d.m4s")
	require.Contains(t, c, `"expr:gte(t,n_forced*3)" -`)
	require.NotContains(t, c, "split_by_time")

	gop := baseSpec()
	gop.UseGopSize = true
	g := cmd(gop, SegmentArgs(gop, 0, SegmentPattern("/tmp/vod", "720p"), InitPath("/tmp/vod", "720p", 1)))
	require.Contains(t, g, "-g 90 -keyint_min 90")
	require.NotContains(t, g, "force_key_frames")
}

func TestMP4Args(t *testing.T) {
	c := cmd(baseSpec(), MP4Args(baseSpec()))
	require.NotContains(t, c, "-fps_mode")
	require.NotContains(t, c, "-bf")
	require.Contains(t, c, `-movflags frag_keyframe+empty_moov+faststart -f mp4 "pipe:1"`)
}

func TestSegmentPaths(t *testing.T) {
	require.Equal(t, "/tmp/vod/720p-%06d.m4s", SegmentPattern("/tmp/vod", "720p"))
	require.Equal(t, "/tmp/vod/720p-000003.m4s", SegmentPath("/tmp/vod", "720p", 3))
	require.Equal(t, "/tmp/vod/720p-init-3.m4s", InitPath("/tmp/vod", "720p", 3))
	require.Equal(t, "720p-init.m4s", InitName("720p"))
}

func TestParseInitName(t *testing.T) {
	q, ok := ParseInitName("direct-init.m4s")
	require.True(t, ok)
	require.Equal(t, "direct", q)

	for _, bad := range []string{"-init.m4s", "a/b-init.m4s", "720p-init.mp4", "720p-000001.m4s"} {
		_, ok := ParseInitName(bad)
		require.False(t, ok, bad)
	}
}

func TestParseSegmentName(t *testing.T) {
	q, id, err := ParseSegmentName("720p-000003.m4s")
	require.NoError(t, err)
	require.Equal(t, "720p", q)
	require.Equal(t, 3, id)

	q, id, err = ParseSegmentName("/tmp/go-vod/my-id-1/max-000042.m4s")
	require.NoError(t, err)
	require.Equal(t, "max", q)
	require.Equal(t, 42, id)

	for _, bad := range []string{"", "720p.m4s", "720p-abc.m4s", "720p-000003.ts", "a-b-c.m4s", "720p-init-1.m4s"} {
		_, _, err := ParseSegmentName(bad)
		require.Error(t, err, bad)
	}
}

func TestParseSegmentLine(t *testing.T) {
	q, id, ok := ParseSegmentLine("  1080p-000003.m4s\n")
	require.True(t, ok)
	require.Equal(t, "1080p", q)
	require.Equal(t, 3, id)

	_, _, ok = ParseSegmentLine("frame=  100 fps=30")
	require.False(t, ok)

	_, _, ok = ParseSegmentLine(`#EXT-X-MAP:URI="/tmp/vod/1080p-init-1.m4s"`)
	require.False(t, ok)
}

func TestQuoteForLog(t *testing.T) {
	require.Equal(t, `-vf "scale=1:2"`, QuoteForLog([]string{"-vf", "scale=1:2"}))
}

func TestRedactArgs(t *testing.T) {
	t.Setenv("GO_VOD_DEBUG", "")
	in := []string{"-seekable", "1", "-headers", "X-Token: secret\r\n", "-i", "http://nc/file/7"}
	out := RedactArgs(in)
	require.Equal(t, []string{"-seekable", "1", "-headers", "<redacted>", "-i", "http://nc/file/7"}, out)
	require.Equal(t, "X-Token: secret\r\n", in[3])
	require.NotContains(t, QuoteForLog(out), "secret")

	plain := []string{"-i", "file.mp4"}
	require.Equal(t, plain, RedactArgs(plain))

	dangling := []string{"-headers"}
	require.Equal(t, dangling, RedactArgs(dangling))

	t.Setenv("GO_VOD_DEBUG", "1")
	require.Equal(t, in, RedactArgs(in))
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
	require.NotContains(t, c, "-tag:v")

	s.CodecName = "hevc"
	require.Contains(t, cmd(s, BuildArgs(s)), `"-c:v" copy "-tag:v" hvc1`)
}

func TestSegmentArgsCopy(t *testing.T) {
	s := baseSpec()
	s.Copy = true
	c := cmd(s, SegmentArgs(s, 2, SegmentPattern("/tmp/vod", "direct"), InitPath("/tmp/vod", "direct", 1)))
	require.NotContains(t, c, "-fps_mode")
	require.NotContains(t, c, "-bf")
	require.Contains(t, c, "-hls_time "+copyHLSTime)
	require.NotContains(t, c, "force_key_frames")
	require.NotContains(t, c, " -g ")
	require.NotContains(t, c, "split_by_time")
}

func TestSegmentTimestamps(t *testing.T) {
	for _, backend := range []string{EncoderX264, EncoderVAAPI, EncoderNVENC} {
		for _, copy := range []bool{false, true} {
			s := baseSpec()
			s.VAAPI, s.NVENC, s.NVENCScale = backend == EncoderVAAPI, backend == EncoderNVENC, "cuda"
			s.Copy, s.StartAt, s.FrameRate = copy, 9, 120
			s.UseGopSize = s.NVENC
			args := SegmentArgs(s, 3, SegmentPattern("/tmp/vod", s.Quality), InitPath("/tmp/vod", s.Quality, 1))
			i := slices.Index(args, "-fps_mode")
			if copy {
				require.Equal(t, -1, i)
			} else {
				require.Greater(t, i, slices.Index(args, "-i"))
				require.Greater(t, i, slices.Index(args, "-f"))
				require.Equal(t, "passthrough", args[i+1])
			}
			require.Equal(t, "9.000000", args[slices.Index(args, "-ss")+1])
			require.Equal(t, "3", args[slices.Index(args, "-start_number")+1])
			require.Contains(t, args, "-copyts")
			require.Equal(t, "+genpts", args[slices.Index(args, "-fflags")+1])
			require.Equal(t, "disabled", args[slices.Index(args, "-avoid_negative_ts")+1])
		}
	}
}

func TestCopySegments(t *testing.T) {
	segs := CopySegments([]float64{0, 2, 6, 10}, 11*time.Second)
	require.Equal(t, []Segment{
		{Start: 0, Duration: 2},
		{Start: 2, Duration: 4},
		{Start: 6, Duration: 4},
		{Start: 10, Duration: 1},
	}, segs)

	// A late first keyframe opens segment 0 instead of a tiny cut.
	segs = CopySegments([]float64{0.067, 4}, 8*time.Second)
	require.Equal(t, []Segment{{Start: 0, Duration: 4}, {Start: 4, Duration: 4}}, segs)

	// Out of order, duplicate and out of range keyframes.
	segs = CopySegments([]float64{4, 0, 4, 2, 12}, 12*time.Second)
	require.Equal(t, []Segment{{Start: 0, Duration: 2}, {Start: 2, Duration: 2}, {Start: 4, Duration: 8}}, segs)

	require.Nil(t, CopySegments(nil, 11*time.Second))
	require.Nil(t, CopySegments([]float64{0}, 0))
}

func TestHWProbeKey(t *testing.T) {
	base := Spec{Bin: "ffmpeg", Width: 1280, Height: 720, VAAPI: true, HDR: true, CodecName: "hevc"}
	other := base
	other.Input, other.Headers, other.StartAt, other.HLS = "http://x/b.mov", "X: y\r\n", 42, true
	other.QF, other.FrameRate, other.ChunkSize, other.Audio = 30, 60, 3, AudioInfo{CodecName: "aac"}
	require.Equal(t, HWProbeKey(base), HWProbeKey(other))

	other = base
	other.Width = 1920
	require.NotEqual(t, HWProbeKey(base), HWProbeKey(other))
}
