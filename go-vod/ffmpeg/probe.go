// Package ffmpeg owns everything that shells out to the ffmpeg toolchain:
// probing inputs, building transcoder arguments and naming output segments.
package ffmpeg

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os/exec"
	"strconv"
	"strings"
	"time"

	ffprobe "github.com/vansante/go-ffprobe"
)

const (
	EncoderCopy  = "copy"
	EncoderX264  = "libx264"
	EncoderVAAPI = "h264_vaapi"
	EncoderNVENC = "h264_nvenc"

	QualityMax = "max"
	CodecH264  = "h264"

	probeTimeout    = 5 * time.Second
	keyframeTimeout = 5 * time.Minute
	defaultBitRate  = 5000000
	defaultFrameNum = 30
	defaultFrameDen = 1
)

type VideoInfo struct {
	Width     int
	Height    int
	Duration  time.Duration
	FrameRate int
	CodecName string
	BitRate   int
	Rotation  int
	HDR       bool
	BitDepth  int
	Audio     AudioInfo
}

// AudioInfo describes the first audio stream; empty when silent.
type AudioInfo struct {
	CodecName  string
	Channels   int
	SampleRate int
	BitRate    int
}

type sideData struct {
	SideDataType string `json:"side_data_type"`
	Rotation     int    `json:"rotation"`
}

type videoStream struct {
	ffprobe.Stream
	SideDataList   []sideData `json:"side_data_list"`
	ColorTransfer  string     `json:"color_transfer"`
	ColorPrimaries string     `json:"color_primaries"`
}

type probeOutput struct {
	Streams []videoStream   `json:"streams"`
	Format  *ffprobe.Format `json:"format"`
}

// Probe runs ffprobe and parses the first video stream.
func Probe(ctx context.Context, bin, path string) (VideoInfo, error) {
	ctx, cancel := context.WithTimeout(ctx, probeTimeout)
	defer cancel()

	args := []string{
		"-v", "error",
		"-show_entries", "format:stream",
		"-of", "json",
		path,
	}
	out, serr, err := runFFprobe(ctx, bin, args...)
	if err != nil {
		return VideoInfo{}, fmt.Errorf("ffprobe %s: %w: %s", path, err, serr)
	}
	return ParseProbeJSON(out)
}

// runFFprobe executes the binary, capturing stdout and stderr.
func runFFprobe(ctx context.Context, bin string, args ...string) (stdout, stderr []byte, err error) {
	cmd := exec.CommandContext(ctx, bin, args...)
	var out, serr bytes.Buffer
	cmd.Stdout = &out
	cmd.Stderr = &serr
	err = cmd.Run()
	return out.Bytes(), serr.Bytes(), err
}

// ParseProbeJSON parses Probe output, defaulting missing fields.
func ParseProbeJSON(data []byte) (VideoInfo, error) {
	var out probeOutput
	if err := json.Unmarshal(data, &out); err != nil {
		return VideoInfo{}, err
	}
	var s *videoStream
	for i := range out.Streams {
		if out.Streams[i].CodecType == "video" {
			s = &out.Streams[i]
			break
		}
	}
	if s == nil {
		return VideoInfo{}, errors.New("no video streams found")
	}

	var duration time.Duration
	if s.Duration != "" {
		if secs, err := strconv.ParseFloat(s.Duration, 64); err == nil {
			duration = time.Duration(secs * float64(time.Second))
		}
	} else if out.Format != nil {
		duration = out.Format.Duration()
	}

	bitRate, err := strconv.Atoi(s.BitRate)
	if err != nil {
		bitRate = defaultBitRate
	}

	return VideoInfo{
		Width:     s.Width,
		Height:    s.Height,
		Duration:  duration,
		FrameRate: parseFrameRate(s.AvgFrameRate),
		CodecName: s.CodecName,
		BitRate:   bitRate,
		Rotation:  probeRotation(*s),
		HDR:       probeHDR(*s),
		BitDepth:  probeBitDepth(s.PixFmt),
		Audio:     probeAudio(out.Streams),
	}, nil
}

// probeAudio describes the first audio stream; empty when silent.
func probeAudio(streams []videoStream) AudioInfo {
	for i := range streams {
		st := &streams[i]
		if st.CodecType != "audio" || st.CodecName == "" {
			continue
		}
		rate, _ := strconv.Atoi(st.SampleRate)
		br, _ := strconv.Atoi(st.BitRate)
		return AudioInfo{
			CodecName:  st.CodecName,
			Channels:   st.Channels,
			SampleRate: rate,
			BitRate:    br,
		}
	}
	return AudioInfo{}
}

// parseFrameRate parses a "num/den" frame rate, defaulting to 30fps.
func parseFrameRate(frac string) int {
	parts := strings.Split(frac, "/")
	if len(parts) != 2 {
		return defaultFrameNum
	}
	num, e1 := strconv.Atoi(parts[0])
	den, e2 := strconv.Atoi(parts[1])
	if e1 != nil || e2 != nil || den == 0 {
		num, den = defaultFrameNum, defaultFrameDen
	}
	return int(float64(num) / float64(den))
}

// probeRotation reads rotation from display-matrix side data, else tags.
func probeRotation(s videoStream) int {
	for _, sd := range s.SideDataList {
		if sd.SideDataType == "Display Matrix" {
			return sd.Rotation
		}
	}
	return s.Tags.Rotate
}

// probeBitDepth reads sample depth from the pix_fmt name, 8 when unknown.
// Matched on explicit suffixes since Contains "12" would match nv12.
func probeBitDepth(pixFmt string) int {
	for _, depth := range []int{10, 12, 14, 16} {
		d := strconv.Itoa(depth)
		if strings.HasSuffix(pixFmt, d+"le") || strings.HasSuffix(pixFmt, d+"be") {
			return depth
		}
	}
	return 8
}

// probeHDR detects HDR from transfer and color metadata.
func probeHDR(s videoStream) bool {
	switch s.ColorTransfer {
	case "smpte2084", "arib-std-b67":
		return true
	}
	// Dolby Vision by codec or RPU side data, including profile 8 tucked
	// into HEVC streams whose transfer tags look SDR alone. Explicit names
	// only: prefix-matching "dv" would catch SDR dvvideo.
	switch s.CodecName {
	case "dvav", "dva1", "dvhe", "dvh1", "dvh2", "dvh3", "dvc1", "dav1":
		return true
	}
	for _, sd := range s.SideDataList {
		if strings.Contains(strings.ToLower(sd.SideDataType), "dolby vision") {
			return true
		}
	}
	if s.ColorSpace == "bt2020nc" || s.ColorSpace == "bt2020c" || s.ColorPrimaries == "bt2020" {
		return probeBitDepth(s.PixFmt) > 8
	}
	return false
}

// Keyframes runs ffprobe and parses keyframe timestamps in seconds.
func Keyframes(ctx context.Context, bin, path string) ([]float64, error) {
	ctx, cancel := context.WithTimeout(ctx, keyframeTimeout)
	defer cancel()

	args := []string{
		"-v", "error",
		"-select_streams", "v:0",
		"-show_entries", "packet=pts_time,flags",
		"-of", "csv=p=0",
		path,
	}
	out, serr, err := runFFprobe(ctx, bin, args...)
	if err != nil {
		return nil, fmt.Errorf("ffprobe keyframes %s: %w: %s", path, err, serr)
	}
	return ParseKeyframes(out), nil
}

// ParseKeyframes parses Keyframes output, keeping keyframe (K) packets,
// so garbage yields an empty slice and callers fall back to re-encoding.
func ParseKeyframes(data []byte) []float64 {
	var out []float64
	for line := range strings.SplitSeq(string(data), "\n") {
		pts, flags, _ := strings.Cut(strings.TrimSpace(line), ",")
		if !strings.Contains(flags, "K") {
			continue
		}
		if ts, err := strconv.ParseFloat(pts, 64); err == nil {
			out = append(out, ts)
		}
	}
	return out
}
