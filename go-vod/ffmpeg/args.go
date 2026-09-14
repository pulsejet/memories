package ffmpeg

import (
	"fmt"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"
)

// Spec describes one transcode job: which input to read, which rendition to
// produce and which encoder backend to use. It is a flat bag of plain values
// (no live server state), so argument building stays pure: the same Spec
// always yields the same argv, reviewable and testable without binaries.
//
// The zero value selects software x264. Set VAAPI or NVENC (never both;
// VAAPI wins) for hardware.
type Spec struct {
	// Bin is the ffmpeg executable. Informational only (callers prepend it
	// via exec.Command); kept so tests render complete command lines.
	Bin string
	// Input is the source file handed to "-i".
	Input string

	// StartAt seeks before decoding; <= 0 emits no "-ss".
	StartAt float64
	// HLS marks segment output (vs progressive file). Only gates rotation
	// handling: manual transpose applies to HLS renditions.
	HLS bool

	// Quality is the rendition label ("480p", …, QualityMax). Anything but
	// QualityMax enables the downscale filter.
	Quality string
	// Width is always the larger source dimension, scaled proportionally
	// (callers swap axes for portrait sources).
	Width, Height int
	// QF is the quality factor: CRF (x264), global_quality (VA-API), CQ (NVENC).
	QF int
	// FrameRate is the probed source fps, truncated to int. Only sizes the
	// GOP when UseGopSize is set.
	FrameRate int
	// Rotation is the probed source rotation (-90, 90, ±180, 0), applied as
	// an explicit transpose filter for HLS when UseTranspose is set.
	Rotation int
	// HDR marks sources needing SDR tonemapping.
	HDR bool
	// Audio is the first audio stream; empty when silent.
	Audio AudioInfo
	// ChunkSize is the target segment length in whole seconds. Drives
	// -hls_time and the forced-keyframe interval.
	ChunkSize int

	// VAAPI selects h264_vaapi on VAAPIDevice; VAAPILowPower adds
	// "-low_power 1" for fixed-function encode blocks.
	VAAPI, VAAPILowPower bool
	// VAAPIDevice is the VA-API render node; empty selects /dev/dri/renderD128.
	VAAPIDevice string
	// NVENC selects h264_nvenc with CUDA offload. NVENCScale picks the scaler
	// ("cuda" or "npp"); NVENCTemporalAQ enables temporal AQ.
	NVENC, NVENCTemporalAQ bool
	NVENCScale             string

	// UseTranspose disables ffmpeg autorotation (-noautorotate) and rotates
	// manually instead. Autorotation fails silently on some hardware paths
	// and never applies to HLS segments; see
	// https://trac.ffmpeg.org/ticket/8329.
	UseTranspose bool
	// ForceSwTranspose rotates on the CPU (hwdownload → transpose → hwupload)
	// to dodge broken hardware transpose filters. Always on for
	// transpose_cuda, which is not trusted at all.
	ForceSwTranspose bool
	// UseGopSize uses a fixed GOP (ChunkSize*FrameRate) instead of forced
	// keyframes, for encoders whose forced keyframes drift (notably NVENC).
	UseGopSize bool
	// Copy stream-copies the video (-c:v copy) instead of re-encoding it,
	// for copy-eligible sources.
	Copy bool
}

// Encoder resolves the video encoder: stream copy, then VAAPI, then NVENC,
// then software x264. The only other stream-copy fast path (original h264
// served as-is for progressive download) bypasses argument building entirely.
func Encoder(s Spec) string {
	switch {
	case s.Copy:
		return EncoderCopy
	case s.VAAPI:
		return EncoderVAAPI
	case s.NVENC:
		return EncoderNVENC
	default:
		return EncoderX264
	}
}

// BuildArgs renders the shared ffmpeg prefix for one rendition: input,
// timestamp handling, filter graph, mapping and encoder quality. It returns
// one argv element per slice entry — flags are appended individually, never
// split out of blank-separated blobs, so paths with spaces can't reshape the
// command line.
//
// In order: quiet logging, hidden banner, input seek (-ss, only when StartAt > 0),
// hardware decode offload on a named "memories" device (explicit
// -init_hw_device/-filter_hw_device, required since ffmpeg 8), -noautorotate
// when transposing manually, input with -copyts/+genpts (post-seek timing
// still refers to source timestamps), the -vf graph (nv12 normalize +
// aspect-preserving downscale, in hardware frames per backend; scale_cuda
// needs passthrough=0), an appended transpose stage for rotated HLS sources,
// fixed mapping (first video re-encoded, optional first audio normalized
// to stereo 48kHz AAC), and
// constant-quality rate control per encoder (crf / global_quality / cq).
// Audio is always normalized to stereo 48kHz AAC: passthrough channel
// counts and sample rates (notably multichannel) yield segments that
// Chrome's MSE audio SourceBuffer rejects with bufferAppendingError.
// Ladder bitrates appear only in playlists, never here. With Copy set, the
// offload, filter graph and rate control collapse to "-c:v copy".
func BuildArgs(s Spec) []string {
	args := []string{"-hide_banner", "-loglevel", "warning"}

	if s.StartAt > 0 {
		args = append(args, "-ss", fmt.Sprintf("%.6f", s.StartAt))
	}

	cv := Encoder(s)
	switch cv {
	case EncoderVAAPI:
		dev := s.VAAPIDevice
		if dev == "" {
			dev = "/dev/dri/renderD128"
		}
		args = append(args,
			"-hwaccel", "vaapi",
			"-hwaccel_device", dev,
			"-hwaccel_output_format", "vaapi",
			"-init_hw_device", "vaapi=memories:"+dev,
			"-filter_hw_device", "memories",
		)
	case EncoderNVENC:
		args = append(args,
			"-hwaccel", "cuda",
			"-hwaccel_output_format", "cuda",
			"-init_hw_device", "cuda=memories",
			"-filter_hw_device", "memories",
		)
	}

	if s.UseTranspose {
		args = append(args, "-noautorotate")
	}

	args = append(args,
		"-i", s.Input,
		"-copyts",
		"-fflags", "+genpts",
	)

	format := "format=nv12"
	scaler := "scale"
	scalerArgs := []string{"force_original_aspect_ratio=decrease"}

	switch cv {
	case EncoderVAAPI:
		format = "format=nv12|vaapi,hwupload"
		scaler = "scale_vaapi"
		scalerArgs = append(scalerArgs, "format=nv12")
	case EncoderNVENC:
		format = "format=nv12|cuda,hwupload"
		scaler = fmt.Sprintf("scale_%s", s.NVENCScale)
		if s.NVENCScale == "cuda" {
			scalerArgs = append(scalerArgs, "passthrough=0")
		}
	}

	if s.Quality != QualityMax {
		maxDim := max(s.Width, s.Height)
		scalerArgs = append(scalerArgs, fmt.Sprintf("w=%d", maxDim), fmt.Sprintf("h=%d", maxDim))
	}

	if cv != EncoderCopy {
		filter := fmt.Sprintf("%s,%s=%s", format, scaler, strings.Join(scalerArgs, ":"))
		if s.HDR {
			filter = tonemapFilter(cv, scaler, scalerArgs)
		}
		if s.HLS && s.UseTranspose {
			transposer := "transpose"
			switch cv {
			case EncoderVAAPI:
				transposer = "transpose_vaapi"
			case EncoderNVENC:
				transposer = fmt.Sprintf("transpose_%s", s.NVENCScale)
			}

			forceSwTranspose := transposer != "transpose" && (s.ForceSwTranspose || transposer == "transpose_cuda")
			if forceSwTranspose {
				transposer = "transpose"
			}

			var transpose string
			switch s.Rotation {
			case -90:
				transpose = fmt.Sprintf("%s=1", transposer)
			case 90:
				transpose = fmt.Sprintf("%s=2", transposer)
			case 180, -180:
				transpose = fmt.Sprintf("%s=1,%s=1", transposer, transposer)
			}

			if transpose != "" {
				if forceSwTranspose {
					pre := "hwdownload,format=nv12"
					post := format
					filter = fmt.Sprintf("%s,%s,%s,%s", filter, pre, transpose, post)
				} else {
					filter = fmt.Sprintf("%s,%s", filter, transpose)
				}
			}
		}

		args = append(args, "-vf", filter)
	}

	args = append(args, "-map", "0:v:0", "-c:v", cv)

	switch cv {
	case EncoderVAAPI:
		args = append(args, "-global_quality", fmt.Sprintf("%d", s.QF))
		if s.VAAPILowPower {
			args = append(args, "-low_power", "1")
		}
	case EncoderNVENC:
		args = append(args,
			"-preset", "p6",
			"-tune", "ll",
			"-rc", "vbr",
			"-rc-lookahead", "30",
			"-cq", fmt.Sprintf("%d", s.QF),
		)
		if s.NVENCTemporalAQ {
			args = append(args, "-temporal-aq", "1")
		}
	case EncoderX264:
		args = append(args,
			"-preset", "faster",
			"-crf", fmt.Sprintf("%d", s.QF),
		)
	}

	if s.Audio.CodecName != "" {
		args = append(args, "-map", "0:a:0?")
		if s.Audio.CodecName == "aac" && s.Audio.Channels >= 1 && s.Audio.Channels <= 2 {
			args = append(args, "-c:a", "copy")
		} else {
			args = append(args,
				"-c:a", "aac",
				"-ac", "2",
				"-ar", "48000",
				"-b:a", "128k",
			)
		}
	}

	return args
}

// tonemapFilter maps HDR to SDR with hable; zscale supplies linear light.
func tonemapFilter(cv, scaler string, scalerArgs []string) string {
	scale := fmt.Sprintf("%s=%s", scaler, strings.Join(scalerArgs, ":"))
	tail := "zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709," +
		"tonemap=hable,zscale=t=bt709:m=bt709:range=tv"
	if cv == EncoderX264 {
		return fmt.Sprintf("%s,format=yuv420p,%s", tail, scale)
	}
	return fmt.Sprintf("hwdownload,%s,format=nv12,hwupload,%s", tail, scale)
}

// SegmentArgs extends BuildArgs with the HLS muxer tail that chops one
// rendition into servable MPEG-TS segments:
//
//   - "-start_number" numbers the first segment. Callers pass the chunk id
//     decremented by one (starting a frame early keeps keyframes aligned), so
//     on-disk segment N is always "<quality>-NNNNNN.ts".
//   - "-avoid_negative_ts disabled" preserves post-seek timestamps instead of
//     shifting them to zero, matching the "-copyts" input side.
//   - Time-based splitting (-hls_flags split_by_time) cuts strictly by the
//     clock so segments stay uniformly sized even when keyframes drift; see
//     the inline comment and https://github.com/videojs/mux.js/pull/138 for
//     why players must tolerate segments that open without a keyframe.
//   - Segments go to SegmentPattern(dir, quality) files; the playlist itself
//     goes to stdout ("-"), where the segment watcher reads names from it.
//   - Keyframes are forced every ChunkSize seconds — fixed GOP when
//     UseGopSize is set, "-force_key_frames expr:…" otherwise — so each
//     boundary lands near a keyframe. Stream copy (Copy) cannot create
//     keyframes, so nothing is forced: the muxer cuts at the next source
//     keyframe past each -hls_time boundary, matching CopySegments.
func SegmentArgs(s Spec, startID int, pattern string) []string {
	args := BuildArgs(s)
	args = append(args,
		"-start_number", fmt.Sprintf("%d", startID),
		"-avoid_negative_ts", "disabled",
		"-f", "hls",
		"-hls_time", fmt.Sprintf("%d", s.ChunkSize),
		"-hls_segment_type", "mpegts",
		"-hls_segment_filename", pattern,
	)

	if !s.Copy {
		// We force a keyframe at the start of each segment.
		// By default, ffmpeg will split only on keyframes, so
		// theoretically we should have perfectly sized chunks.
		//
		// However, the keyframes can be misaligned with the
		// segment start even after forcing. To get around this,
		// we chop the segments by time instead of keyframes.
		//
		// Technically this doesn't work with MSE (at least on Chrome),
		// but video.js can work around this by fusing the
		// segment with the previous GOP if no keyframe is found
		// at the start of the segment.
		// https://github.com/videojs/mux.js/pull/138
		args = append(args, "-hls_flags", "split_by_time")

		if s.UseGopSize && s.FrameRate > 0 {
			gop := fmt.Sprintf("%d", s.ChunkSize*s.FrameRate)
			args = append(args, "-g", gop, "-keyint_min", gop)
		} else {
			args = append(args, "-force_key_frames", fmt.Sprintf("expr:gte(t,n_forced*%d)", s.ChunkSize))
		}
	}

	return append(args, "-")
}

// MP4Args extends BuildArgs for progressive ("full video") downloads on
// stdout: empty moov up front plus keyframe fragments, so playback starts
// before the transcode finishes instead of waiting on a trailing moov atom.
func MP4Args(s Spec) []string {
	args := BuildArgs(s)
	return append(args,
		"-movflags", "frag_keyframe+empty_moov+faststart",
		"-f", "mp4", "pipe:1",
	)
}

// Segment is one stream-copy HLS segment: absolute start and duration.
type Segment struct {
	Start, Duration float64
}

// CopySegments groups keyframes into HLS-sized segments, mirroring the
// muxer's stream-copy splitting. Nil when no grid can be derived.
func CopySegments(keyframes []float64, duration time.Duration, chunkSize int) []Segment {
	total := duration.Seconds()
	if len(keyframes) == 0 || total <= 0 || chunkSize <= 0 {
		return nil
	}

	size := float64(chunkSize)
	bounds := []float64{0}
	for _, k := range slices.Sorted(slices.Values(keyframes)) {
		if k < 0 || k >= total {
			continue
		}
		if k-bounds[len(bounds)-1] >= size {
			bounds = append(bounds, k)
		}
	}

	segs := make([]Segment, 0, len(bounds))
	for i, b := range bounds {
		end := total
		if i+1 < len(bounds) {
			end = bounds[i+1]
		}
		if end-b <= 0 {
			continue
		}
		segs = append(segs, Segment{Start: b, Duration: end - b})
	}
	if len(segs) == 0 {
		return nil
	}
	return segs
}

// SegmentPattern is the "-hls_segment_filename" template for one rendition:
// "<dir>/<quality>-%06d.ts", expanded by the HLS muxer into zero-padded
// segment numbers honoring "-start_number".
func SegmentPattern(dir, quality string) string {
	return filepath.Join(dir, quality+"-%06d.ts")
}

// SegmentPath resolves one concrete segment file; the inverse of
// ParseSegmentName for well-formed names.
func SegmentPath(dir, quality string, id int) string {
	return filepath.Join(dir, fmt.Sprintf("%s-%06d.ts", quality, id))
}

// segmentNameRe is the single definition of a segment file name: rendition
// label, dash, decimal number, ".ts". Labels never contain dashes, so the
// first dash always separates label from number.
var segmentNameRe = regexp.MustCompile(`^([A-Za-z0-9]+)-(\d+)\.ts$`)

// ParseSegmentName splits a segment name into label and number. It matches on
// the base name only, so temp directories containing dashes can't throw off
// the parse; anything else is an error.
func ParseSegmentName(name string) (quality string, id int, err error) {
	m := segmentNameRe.FindStringSubmatch(filepath.Base(name))
	if m == nil {
		return "", 0, fmt.Errorf("invalid segment name %q", name)
	}
	n, err := strconv.Atoi(m[2])
	if err != nil {
		return "", 0, fmt.Errorf("invalid segment name %q", name)
	}
	return m[1], n, nil
}

// ParseSegmentLine extracts a finished segment from one ffmpeg stdout line.
// The HLS muxer prints the growing playlist there, so a segment name only
// surfaces once its segment is finalized — that ordering is what makes the
// line a completion signal. Other log lines yield ok=false.
func ParseSegmentLine(line string) (quality string, id int, ok bool) {
	quality, id, err := ParseSegmentName(strings.TrimSpace(line))
	if err != nil {
		return "", 0, false
	}
	return quality, id, true
}

// QuoteForLog renders argv for log lines, quoting elements with
// shell-significant characters. Log output only, never feed it back to exec.
func QuoteForLog(args []string) string {
	const invalidChars = " =:\"\\\n\t"
	quoted := make([]string, len(args))
	for i, arg := range args {
		if strings.ContainsAny(arg, invalidChars) {
			quoted[i] = fmt.Sprintf("\"%s\"", arg)
		} else {
			quoted[i] = arg
		}
	}
	return strings.Join(quoted, " ")
}
