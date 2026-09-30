package ffmpeg

import (
	"context"
	"fmt"
	"os/exec"
	"time"
)

// ProbeVAAPIOpenCL tests decoding, scaling, interop and encoding a real frame.
// Filter/device listings alone cannot establish whether hwmap works.
func ProbeVAAPIOpenCL(ctx context.Context, s Spec) error {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	s.VAAPIOpenCL = true
	s.Audio = AudioInfo{}
	args := BuildArgs(s)
	args = append(args, "-nostdin", "-abort_on", "empty_output", "-frames:v", "1", "-an", "-f", "null", "-")
	out, err := exec.CommandContext(ctx, s.Bin, args...).CombinedOutput()
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if err != nil {
		return fmt.Errorf("VA-API/OpenCL probe: %w: %s", err, out)
	}
	return nil
}
