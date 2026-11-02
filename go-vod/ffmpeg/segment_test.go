package ffmpeg

import (
	"bytes"
	"encoding/binary"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/require"
)

// firstTfdt returns the first track fragment decode time in an fMP4 segment.
func firstTfdt(t *testing.T, path string) uint64 {
	t.Helper()
	b, err := os.ReadFile(path)
	require.NoError(t, err)
	i := bytes.Index(b, []byte("tfdt"))
	require.Positive(t, i, path)
	if b[i+4] == 1 {
		return binary.BigEndian.Uint64(b[i+8:])
	}
	return uint64(binary.BigEndian.Uint32(b[i+8:]))
}

// A seek restart must write the same init and keep absolute timestamps, so
// its segments fit the init the player cached from the first run.
func TestSegmentArgsRestartFMP4(t *testing.T) {
	bin, err := exec.LookPath("ffmpeg")
	if err != nil {
		t.Skip("ffmpeg not installed")
	}

	dir := t.TempDir()
	src := filepath.Join(dir, "src.mp4")
	out, err := exec.Command(bin, "-hide_banner", "-loglevel", "error",
		"-f", "lavfi", "-i", "testsrc2=s=320x180:r=30",
		"-f", "lavfi", "-i", "sine=sample_rate=48000",
		"-t", "15", "-c:v", "libx264", "-bf", "3", "-c:a", "aac", "-ac", "2", src).CombinedOutput()
	require.NoError(t, err, string(out))

	run := func(n int, startAt float64, startNumber int) {
		s := Spec{
			Input: src, StartAt: startAt, HLS: true, Quality: "180p",
			Width: 320, Height: 180, QF: 30, FrameRate: 30, ChunkSize: 3, BitDepth: 8,
			Audio: AudioInfo{CodecName: "aac", Channels: 2},
		}
		args := SegmentArgs(s, startNumber, SegmentPattern(dir, fmt.Sprintf("run%d", n)), InitPath(dir, "180p", n))
		out, err := exec.Command(bin, args...).CombinedOutput()
		require.NoError(t, err, string(out))
	}
	run(1, 0, 0)
	run(2, 9, 3) // restart for chunk 4, one chunk early

	init1, err := os.ReadFile(InitPath(dir, "180p", 1))
	require.NoError(t, err)
	init2, err := os.ReadFile(InitPath(dir, "180p", 2))
	require.NoError(t, err)
	require.Equal(t, init1, init2)

	for id := 3; id <= 4; id++ {
		a := firstTfdt(t, SegmentPath(dir, "run1", id))
		require.Positive(t, a)
		require.Equal(t, a, firstTfdt(t, SegmentPath(dir, "run2", id)), "segment %d", id)
	}
}
