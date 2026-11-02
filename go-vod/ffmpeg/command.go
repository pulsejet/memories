package ffmpeg

import (
	"context"
	"os/exec"
	"syscall"
)

// Command builds every ffmpeg/ffprobe child. Pdeathsig kills it when go-vod
// exits for any reason (restart, crash, log.Fatal), so no transcode outlives
// the server, not even a paused (SIGSTOP) one.
func Command(ctx context.Context, bin string, args ...string) *exec.Cmd {
	cmd := exec.CommandContext(ctx, bin, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Pdeathsig: syscall.SIGKILL}
	return cmd
}
