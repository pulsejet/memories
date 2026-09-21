package core

import (
	"context"
	"log"

	"github.com/pulsejet/memories/go-vod/ffmpeg"
)

func (s *Stream) transcodeSpec(startAt float64, isHLS bool) ffmpeg.Spec {
	spec := s.spec(startAt, isHLS)
	// Software transpose uploads through the VA-API filter device, whereas
	// the OpenCL graph uses an OpenCL filter device. Keep that workaround.
	if ffmpeg.Encoder(spec) != ffmpeg.EncoderVAAPI || !spec.HDR || spec.ForceSwTranspose {
		return spec
	}

	// Cache per rendition: dimensions and rotation can affect interop.
	s.tonemapOnce.Do(func() {
		if err := ffmpeg.ProbeVAAPIOpenCL(context.Background(), spec); err != nil {
			log.Printf("%s-%s: using software HDR tonemapping: %v", s.m.id, s.quality, err)
			return
		}
		s.vaapiOpenCL = true
	})
	spec.VAAPIOpenCL = s.vaapiOpenCL
	return spec
}
