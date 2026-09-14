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
}

type sideData struct {
	SideDataType string `json:"side_data_type"`
	Rotation     int    `json:"rotation"`
}

type videoStream struct {
	ffprobe.Stream
	SideDataList []sideData `json:"side_data_list"`
}

type probeOutput struct {
	Streams []videoStream   `json:"streams"`
	Format  *ffprobe.Format `json:"format"`
}

func ProbeArgs(path string) []string {
	return []string{
		"-v", "error",
		"-show_entries", "format:stream",
		"-select_streams", "v",
		"-of", "json",
		path,
	}
}

var runFFprobe = func(ctx context.Context, bin string, args ...string) (stdout, stderr []byte, err error) {
	cmd := exec.CommandContext(ctx, bin, args...)
	var out, serr bytes.Buffer
	cmd.Stdout = &out
	cmd.Stderr = &serr
	err = cmd.Run()
	return out.Bytes(), serr.Bytes(), err
}

func Probe(ctx context.Context, bin, path string) (VideoInfo, error) {
	ctx, cancel := context.WithTimeout(ctx, probeTimeout)
	defer cancel()
	out, serr, err := runFFprobe(ctx, bin, ProbeArgs(path)...)
	if err != nil {
		return VideoInfo{}, fmt.Errorf("ffprobe %s: %w: %s", path, err, serr)
	}
	return ParseProbeJSON(out)
}

func ParseProbeJSON(data []byte) (VideoInfo, error) {
	var out probeOutput
	if err := json.Unmarshal(data, &out); err != nil {
		return VideoInfo{}, err
	}
	if len(out.Streams) == 0 {
		return VideoInfo{}, errors.New("no video streams found")
	}
	s := out.Streams[0]

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
		Rotation:  probeRotation(s),
	}, nil
}

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

func probeRotation(s videoStream) int {
	for _, sd := range s.SideDataList {
		if sd.SideDataType == "Display Matrix" {
			return sd.Rotation
		}
	}
	return s.Tags.Rotate
}
