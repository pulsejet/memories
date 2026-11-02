package core

import (
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/stretchr/testify/require"
)

// End to end through real ffmpeg: init, first segment, then a seek restart
// whose segment must still decode at its source time with the cached init.
func TestFMP4SeekRestart(t *testing.T) {
	ffmpegBin, err1 := exec.LookPath("ffmpeg")
	ffprobeBin, err2 := exec.LookPath("ffprobe")
	if err1 != nil || err2 != nil {
		t.Skip("ffmpeg not installed")
	}

	for _, tc := range []struct {
		name, encoder, quality string
		chunk                  int
		start                  float64
	}{
		{"copy", "libx264", QUALITY_DIRECT, 8, 16}, // 2s source GOP
		{"transcode", "libx265", QUALITY_MAX, 8, 24},
	} {
		t.Run(tc.name, func(t *testing.T) {
			dir := t.TempDir()
			src := filepath.Join(dir, "src.mp4")
			out, err := exec.Command(ffmpegBin, "-hide_banner", "-loglevel", "error",
				"-f", "lavfi", "-i", "testsrc2=s=320x180:r=30",
				"-f", "lavfi", "-i", "sine=sample_rate=48000",
				"-t", "30", "-c:v", tc.encoder, "-g", "60", "-c:a", "aac", "-ac", "2", src).CombinedOutput()
			if err != nil {
				t.Skipf("cannot encode source: %s", out)
			}
			srv := httptest.NewServer(http.FileServer(http.Dir(dir)))
			defer srv.Close()

			cfg := config.Defaults("test")
			cfg.TempDir = t.TempDir()
			cfg.FFmpeg, cfg.FFprobe = ffmpegBin, ffprobeBin
			m, err := NewManager(NewManagerArgs{
				C: cfg,
				ManagerParams: ManagerParams{
					URL: srv.URL + "/src.mp4", StreamID: "e2e", FileID: 1, ServiceToken: "x",
					TConfig: config.TCfg{ChunkSize: 3, QF: 30},
				},
				Generation: 1,
				Idle:       make(chan IdleEvent, 1),
			})
			require.NoError(t, err)
			defer m.Destroy()
			require.True(t, m.HasStream(tc.quality))

			serve := func(f func(http.ResponseWriter)) []byte {
				w := httptest.NewRecorder()
				f(w)
				require.Equal(t, http.StatusOK, w.Code)
				require.Equal(t, "video/mp4", w.Header().Get("Content-Type"))
				return w.Body.Bytes()
			}
			// Init and first segment together share one run.
			go m.ServeInit(httptest.NewRecorder(), tc.quality)
			time.Sleep(50 * time.Millisecond)
			serve(func(w http.ResponseWriter) { m.ServeChunk(w, tc.quality, 0) })
			stream := m.streams[tc.quality]
			stream.mutex.Lock()
			runs := stream.run
			stream.mutex.Unlock()
			require.Equal(t, 1, runs)

			// A far segment restarts the run; the init still serves.
			initCh := make(chan *httptest.ResponseRecorder, 1)
			go func() {
				w := httptest.NewRecorder()
				m.ServeInit(w, tc.quality)
				initCh <- w
			}()
			time.Sleep(50 * time.Millisecond)
			serve(func(w http.ResponseWriter) { m.ServeChunk(w, tc.quality, tc.chunk+1) })
			initRes := <-initCh
			require.Equal(t, http.StatusOK, initRes.Code)
			init := initRes.Body.Bytes()
			serve(func(w http.ResponseWriter) { m.ServeChunk(w, tc.quality, 0) })
			seg := serve(func(w http.ResponseWriter) { m.ServeChunk(w, tc.quality, tc.chunk) })
			require.Equal(t, init, serve(func(w http.ResponseWriter) { m.ServeInit(w, tc.quality) }))
			// The first run's init stays; later runs drop their copy.
			inits, err := filepath.Glob(filepath.Join(m.tempDir, tc.quality+"-init-*.m4s"))
			require.NoError(t, err)
			require.Equal(t, []string{filepath.Join(m.tempDir, tc.quality+"-init-1.m4s")}, inits)

			joined := filepath.Join(dir, "joined.mp4")
			require.NoError(t, os.WriteFile(joined, append(append([]byte{}, init...), seg...), 0644))
			out, err = exec.Command(ffprobeBin, "-v", "error", "-select_streams", "v",
				"-show_entries", "packet=pts_time,flags", "-of", "csv=p=0", joined).Output()
			require.NoError(t, err)
			first, flags, _ := strings.Cut(strings.SplitN(string(out), "\n", 2)[0], ",")
			pts, err := strconv.ParseFloat(first, 64)
			require.NoError(t, err)
			require.InDelta(t, tc.start, pts, 0.1)
			require.Contains(t, flags, "K")
		})
	}
}
