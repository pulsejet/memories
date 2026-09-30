package ffmpeg

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

func TestProbeVAAPIOpenCL(t *testing.T) {
	dir := t.TempDir()
	argsPath := filepath.Join(dir, "args")
	t.Setenv("PROBE_ARGS", argsPath)
	s := baseSpec()
	s.VAAPI, s.HDR, s.UseTranspose, s.Rotation = true, true, true, -90
	s.StartAt = 12
	s.Bin = filepath.Join(dir, "ffmpeg")
	require.NoError(t, os.WriteFile(s.Bin, []byte("#!/bin/sh\nprintf '%s\\n' \"$@\" > \"$PROBE_ARGS\"\n"), 0755))
	require.NoError(t, ProbeVAAPIOpenCL(context.Background(), s))
	data, err := os.ReadFile(argsPath)
	require.NoError(t, err)
	args := strings.Split(strings.TrimSpace(string(data)), "\n")
	require.Equal(t, "12.000000", args[slices.Index(args, "-ss")+1])
	require.Contains(t, args, "opencl=memories_opencl@memories")
	require.Contains(t, args[slices.Index(args, "-vf")+1], "transpose_vaapi=1")
	require.NotContains(t, args, "-c:a")
	require.Equal(t, []string{"-nostdin", "-abort_on", "empty_output", "-frames:v", "1", "-an", "-f", "null", "-"}, args[len(args)-9:])
	require.False(t, s.VAAPIOpenCL, "probing must not mutate the caller's spec")
}

func TestProbeVAAPIOpenCLFailure(t *testing.T) {
	s := baseSpec()
	s.Bin = filepath.Join(t.TempDir(), "ffmpeg")
	require.NoError(t, os.WriteFile(s.Bin, []byte("#!/bin/sh\necho 'interop unavailable' >&2\nexit 1\n"), 0755))
	err := ProbeVAAPIOpenCL(context.Background(), s)
	require.ErrorContains(t, err, "interop unavailable")

	require.NoError(t, os.WriteFile(s.Bin, []byte("#!/bin/sh\nexec sleep 30\n"), 0755))
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	require.ErrorIs(t, ProbeVAAPIOpenCL(ctx, s), context.DeadlineExceeded)
}
