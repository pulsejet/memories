package ffmpeg

import (
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

func TestPlanStoryboardShort(t *testing.T) {
	p := PlanStoryboard(70 * time.Second)
	require.InDelta(t, 5.0, p.Interval, 1e-9)
	require.Equal(t, 14, p.Count)
	require.Equal(t, 1, p.Sprites)
	require.Equal(t, 2, p.GridRows)
	require.Equal(t, 20, p.PerGrid)
}

func TestPlanStoryboardLong(t *testing.T) {
	p := PlanStoryboard(2 * time.Hour)
	require.GreaterOrEqual(t, p.Interval, 5.0)
	require.LessOrEqual(t, p.Count, StoryboardMaxThumbs)
	require.Equal(t, 150, p.Count)
	require.Equal(t, 2, p.Sprites)
	require.Equal(t, 10, p.GridRows)
}

func TestPlanStoryboardTiny(t *testing.T) {
	p := PlanStoryboard(2 * time.Second)
	require.Equal(t, 1, p.Count)
	require.Equal(t, 1, p.Sprites)
	require.Equal(t, 1, p.GridRows)
}

func TestStoryboardVTT(t *testing.T) {
	p := PlanStoryboard(12 * time.Second)
	require.Equal(t, 3, p.Count)

	vtt := p.VTT("")
	require.True(t, strings.HasPrefix(vtt, "WEBVTT\n"))
	require.Contains(t, vtt, "00:00:00.000 --> 00:00:05.000\nstoryboard-0.jpg#xywh=0,0,160,90")
	require.Contains(t, vtt, "00:00:05.000 --> 00:00:10.000\nstoryboard-0.jpg#xywh=160,0,160,90")
	require.Contains(t, vtt, "00:00:10.000 --> 00:00:12.000\nstoryboard-0.jpg#xywh=320,0,160,90")

	// Share tokens ride into every sprite URL like m3u8 segments.
	vtt = p.VTT("?token=abc")
	require.Contains(t, vtt, "00:00:00.000 --> 00:00:05.000\nstoryboard-0.jpg?token=abc#xywh=0,0,160,90")
}

func TestStoryboardCueMultisprite(t *testing.T) {
	p := PlanStoryboard(2 * time.Hour)
	_, _, sprite, _, _ := p.Cue(0)
	require.Equal(t, "storyboard-0.jpg", sprite)
	_, _, sprite, _, _ = p.Cue(99)
	require.Equal(t, "storyboard-0.jpg", sprite)
	start, end, sprite, x, y := p.Cue(100)
	require.Equal(t, "storyboard-1.jpg", sprite)
	require.Equal(t, 0, x)
	require.Equal(t, 0, y)
	require.InDelta(t, 100*p.Interval, start, 1e-9)
	require.InDelta(t, 101*p.Interval, end, 1e-9)
}

func TestFormatVTTTime(t *testing.T) {
	require.Equal(t, "00:00:00.000", FormatVTTTime(0))
	require.Equal(t, "00:00:05.000", FormatVTTTime(5))
	require.Equal(t, "00:01:05.250", FormatVTTTime(65.25))
	require.Equal(t, "01:02:03.004", FormatVTTTime(3723.004))
}

func TestStoryboardArgs(t *testing.T) {
	args := StoryboardArgs("http://nc/file/7", "", 5, 10, 2, 1, "storyboard-%d.jpg")
	require.Equal(t, []string{
		"-hide_banner", "-loglevel", "warning",
		"-skip_frame", "nokey",
		"-multiple_requests", "1",
		"-seekable", "1",
		"-reconnect", "1",
		"-reconnect_on_network_error", "1",
		"-reconnect_on_http_error", "429,5xx",
		"-reconnect_streamed", "1",
		"-reconnect_delay_max", "5",
		"-reconnect_max_retries", "10",
		"-reconnect_delay_total_max", "30",
		"-respect_retry_after", "1",
		"-i", "http://nc/file/7",
		"-an",
		"-vf", "fps=1/5.000000:eof_action=pass,scale=160:90:force_original_aspect_ratio=decrease,format=yuv420p,pad=160:90:(ow-iw)/2:(oh-ih)/2,tile=10x2",
		"-start_number", "0",
		"-frames:v", "1",
		"-q:v", "4",
		"-f", "image2",
		"storyboard-%d.jpg",
	}, args)

	args = StoryboardArgs("http://nc/file/7", "Authorization: Basic eA==\r\n", 5, 10, 2, 1, "storyboard-%d.jpg")
	require.Equal(t, "http://nc/file/7", args[slices.Index(args, "-i")+1])
	require.Equal(t, "Authorization: Basic eA==\r\n", args[slices.Index(args, "-headers")+1])
	require.Less(t, slices.Index(args, "-headers"), slices.Index(args, "-i"))
}
