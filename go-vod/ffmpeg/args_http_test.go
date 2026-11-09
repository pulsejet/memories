package ffmpeg

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/require"
)

func TestDetectHTTPOptions(t *testing.T) {
	orig := reconnectArgs
	defer func() { reconnectArgs = orig }()

	DetectHTTPOptions(filepath.Join(t.TempDir(), "missing"))
	require.Equal(t, orig, reconnectArgs)

	bin := filepath.Join(t.TempDir(), "ffprobe")
	help := "  -reconnect         <boolean>\n  -reconnect_at_eof  <boolean>\n  -reconnect_delay_max <int>\n"
	require.NoError(t, os.WriteFile(bin, []byte("#!/bin/sh\nprintf '"+help+"'\n"), 0o755))
	DetectHTTPOptions(bin)
	require.Equal(t, []string{"-reconnect", "1", "-reconnect_delay_max", "5"}, reconnectArgs)
}
