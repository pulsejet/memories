package core

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"github.com/stretchr/testify/require"
)

func tonemapStream(t *testing.T, fail bool) (*Stream, string) {
	t.Helper()
	dir := t.TempDir()
	count := filepath.Join(dir, "calls")
	t.Setenv("PROBE_CALLS", count)
	bin := filepath.Join(dir, "ffmpeg")
	script := "#!/bin/sh\necho call >> \"$PROBE_CALLS\"\n"
	if fail {
		script += "echo 'OpenCL unavailable' >&2\nexit 1\n"
	}
	require.NoError(t, os.WriteFile(bin, []byte(script), 0755))
	c := &config.Config{FFmpeg: bin}
	m := &Manager{
		c: c, url: "http://localhost/hdr.mov",
		tc:    config.TCfg{VAAPI: true, VAAPIDevice: "/dev/dri/renderD129", UseTranspose: true},
		probe: &ProbeVideoData{HDR: true, Rotation: -90},
	}
	return &Stream{c: c, m: m, quality: "720p", width: 1280, height: 720}, count
}

func TestTonemapSelection(t *testing.T) {
	for _, fail := range []bool{false, true} {
		s, count := tonemapStream(t, fail)
		results := make(chan ffmpeg.Spec, 8)
		for range 8 {
			go func() { results <- s.transcodeSpec(0, true) }()
		}
		for range 8 {
			spec := <-results
			require.Equal(t, !fail, spec.VAAPIOpenCL)
			args := strings.Join(ffmpeg.BuildArgs(spec), " ")
			if fail {
				require.Contains(t, args, "hwdownload,format=nv12,scale=")
				require.Contains(t, args, "zscale=")
				require.NotContains(t, args, "opencl")
			} else {
				require.Contains(t, args, "tonemap_opencl=")
			}
		}
		// Seeks and progressive output reuse the same decision.
		require.Equal(t, !fail, s.transcodeSpec(12, true).VAAPIOpenCL)
		require.Equal(t, !fail, s.transcodeSpec(0, false).VAAPIOpenCL)
		data, err := os.ReadFile(count)
		require.NoError(t, err)
		require.Equal(t, "call\n", string(data))

		other := &Stream{c: s.c, m: s.m, quality: QUALITY_MAX}
		require.Equal(t, !fail, other.transcodeSpec(0, true).VAAPIOpenCL)
		data, err = os.ReadFile(count)
		require.NoError(t, err)
		require.Equal(t, "call\ncall\n", string(data), "each rendition needs its own interop check")
	}
}

func TestTonemapProbeSkipped(t *testing.T) {
	for _, kind := range []string{"sdr", "software", "nvenc", "copy", "software transpose"} {
		t.Run(kind, func(t *testing.T) {
			s, count := tonemapStream(t, false)
			switch kind {
			case "sdr":
				s.m.probe.HDR = false
			case "software":
				s.m.tc.VAAPI = false
			case "nvenc":
				s.m.tc.VAAPI, s.m.tc.NVENC = false, true
			case "copy":
				s.quality = QUALITY_DIRECT
				s.m.srcSegments = []ffmpeg.Segment{{Start: 0, Duration: 3}}
			case "software transpose":
				s.m.tc.ForceSwTranspose = true
			}
			require.False(t, s.transcodeSpec(0, true).VAAPIOpenCL)
			require.NoFileExists(t, count)
		})
	}
}
