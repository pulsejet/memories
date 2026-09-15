package ffmpeg

import (
	"context"
	"fmt"
	"os/exec"
	"strings"
	"time"
)

const (
	// StoryboardInterval is the minimum spacing between thumbnails.
	StoryboardInterval = 5.0
	// StoryboardMaxThumbs caps the total thumbnail count; longer videos
	// get sparser thumbs so generation stays a fast single pass.
	StoryboardMaxThumbs = 150
	// StoryboardCols x StoryboardRows tiles per sprite sheet.
	StoryboardCols = 10
	StoryboardRows = 10
	// StoryboardWidth x StoryboardHeight is the fixed tile size;
	// sources are letterboxed to fit.
	StoryboardWidth  = 160
	StoryboardHeight = 90
	// StoryboardQuality is the MJPEG q:v for sprites (2-5 sane).
	StoryboardQuality = 4

	storyboardTimeout = 10 * time.Minute
)

// StoryboardPlan describes one storyboard: uniform tiles packed into
// sprite sheets, referenced by a VTT with relative sprite file names.
// GridRows shrinks the tile grid to fit short videos so sprites carry
// no black padding; PerGrid is the resulting per-sprite capacity.
type StoryboardPlan struct {
	Interval float64
	Count    int
	Sprites  int
	ThumbW   int
	ThumbH   int
	Cols     int
	GridRows int
	PerGrid  int
	Duration float64
}

// PlanStoryboard spaces thumbs at least StoryboardInterval apart,
// thinning out long videos to stay under StoryboardMaxThumbs.
func PlanStoryboard(duration time.Duration) StoryboardPlan {
	secs := duration.Seconds()
	if secs <= 0 {
		secs = StoryboardInterval
	}
	interval := StoryboardInterval
	if n := secs / StoryboardMaxThumbs; n > interval {
		interval = n
	}
	count := int((secs + interval - 1e-9) / interval)
	if count < 1 {
		count = 1
	}
	rows := (count + StoryboardCols - 1) / StoryboardCols
	if rows > StoryboardRows {
		rows = StoryboardRows
	}
	per := StoryboardCols * rows
	sprites := (count + per - 1) / per
	return StoryboardPlan{
		Interval: interval,
		Count:    count,
		Sprites:  sprites,
		ThumbW:   StoryboardWidth,
		ThumbH:   StoryboardHeight,
		Cols:     StoryboardCols,
		GridRows: rows,
		PerGrid:  per,
		Duration: secs,
	}
}

// SpriteName is the relative file name of the nth sprite sheet.
func SpriteName(n int) string {
	return fmt.Sprintf("storyboard-%d.jpg", n)
}

// Cue maps thumbnail i to its time range and tile coordinates.
func (p StoryboardPlan) Cue(i int) (start, end float64, sprite string, x, y int) {
	start = float64(i) * p.Interval
	end = start + p.Interval
	if end > p.Duration || i == p.Count-1 {
		end = p.Duration
	}
	tile := i % p.PerGrid
	return start, end, SpriteName(i / p.PerGrid), (tile % p.Cols) * p.ThumbW, (tile / p.Cols) * p.ThumbH
}

// VTT renders the plan as a WebVTT storyboard with relative sprite URLs,
// in the format vidstack consumes (see files.vidstack.io/sprite-fight).
// Query carries the request's query string (e.g. share tokens, same as
// m3u8 playlists) into every sprite URL; the plan is stored as JSON and
// the VTT is rendered per request because of it.
func (p StoryboardPlan) VTT(query string) string {
	var b strings.Builder
	b.WriteString("WEBVTT\n")
	for i := 0; i < p.Count; i++ {
		start, end, sprite, x, y := p.Cue(i)
		fmt.Fprintf(&b, "\n%s --> %s\n%s%s#xywh=%d,%d,%d,%d\n",
			FormatVTTTime(start), FormatVTTTime(end), sprite, query, x, y, p.ThumbW, p.ThumbH)
	}
	return b.String()
}

// FormatVTTTime renders seconds as HH:MM:SS.mmm.
func FormatVTTTime(s float64) string {
	if s < 0 {
		s = 0
	}
	ms := int(s*1000 + 0.5)
	return fmt.Sprintf("%02d:%02d:%02d.%03d", ms/3600000, ms/60000%60, ms/1000%60, ms%1000)
}

// StoryboardArgs renders one argv element per entry (bin excluded, same
// convention as BuildArgs). A single pass decodes keyframes only
// (-skip_frame nokey), picks frames at the planned interval, scales and
// tiles them into sprite sheets at pattern (printf-style, e.g. storyboard-%d.jpg).
func StoryboardArgs(input string, interval float64, cols, rows, sprites int, pattern string) []string {
	filter := fmt.Sprintf("fps=1/%.6f,scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,tile=%dx%d",
		interval, StoryboardWidth, StoryboardHeight, StoryboardWidth, StoryboardHeight,
		cols, rows)
	return []string{
		"-hide_banner", "-loglevel", "warning",
		"-skip_frame", "nokey",
		"-i", input,
		"-an",
		"-vf", filter,
		"-start_number", "0",
		"-frames:v", fmt.Sprintf("%d", sprites),
		"-q:v", fmt.Sprintf("%d", StoryboardQuality),
		// Explicit muxer: the cache pattern carries a .part suffix
		// while rendering, which ffmpeg can't sniff a format from.
		"-f", "image2",
		pattern,
	}
}

// BuildStoryboard runs ffmpeg to render the plan's sprites at pattern.
// Autorotation stays on (no -noautorotate) so thumbs match display orientation.
func BuildStoryboard(ctx context.Context, bin, input string, plan StoryboardPlan, pattern string) error {
	ctx, cancel := context.WithTimeout(ctx, storyboardTimeout)
	defer cancel()

	// StoryboardArgs excludes bin (same convention as BuildArgs).
	cmd := exec.CommandContext(ctx, bin, StoryboardArgs(input, plan.Interval, plan.Cols, plan.GridRows, plan.Sprites, pattern)...)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("storyboard %s: %w: %s", input, err, strings.TrimSpace(string(out)))
	}
	return nil
}
