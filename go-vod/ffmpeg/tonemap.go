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
	s.VAAPIOpenCL = true
	return probeTonemap(ctx, s, "VA-API/OpenCL")
}

// ProbeNVENCCUDA checks the optional tonemap_cuda filter on the actual source.
func ProbeNVENCCUDA(ctx context.Context, s Spec) error {
	s.NVENCCUDA = true
	return probeTonemap(ctx, s, "NVENC/CUDA")
}

func probeTonemap(ctx context.Context, s Spec, backend string) error {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	s.Audio = AudioInfo{}
	args := BuildArgs(s)
	args = append(args, "-nostdin", "-abort_on", "empty_output", "-frames:v", "1", "-an", "-f", "null", "-")
	out, err := exec.CommandContext(ctx, s.Bin, args...).CombinedOutput()
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if err != nil {
		return fmt.Errorf("%s probe: %w: %s", backend, err, out)
	}
	return nil
}
