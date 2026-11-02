package core

import (
	"context"
	"log"
	"sync"

	"github.com/pulsejet/memories/go-vod/ffmpeg"
)

// tonemapOK shares passed GPU tonemap probes by HWProbeKey.
// Failures may be transient, so they are not shared.
var tonemapOK sync.Map

func probeTonemap(spec ffmpeg.Spec, probe func(context.Context, ffmpeg.Spec) error) error {
	key := ffmpeg.HWProbeKey(spec)
	if _, ok := tonemapOK.Load(key); ok {
		return nil
	}
	if err := probe(context.Background(), spec); err != nil {
		return err
	}
	tonemapOK.Store(key, struct{}{})
	return nil
}

func (s *Stream) transcodeSpec(startAt float64, isHLS bool) ffmpeg.Spec {
	spec := s.spec(startAt, isHLS)

	// The OpenCL graph uses an OpenCL filter device, whereas software
	// transpose uploads through the VA-API filter device. Keep that workaround.
	if ffmpeg.Encoder(spec) == ffmpeg.EncoderVAAPI && spec.HDR && !spec.ForceSwTranspose {
		// Cache per rendition: dimensions and rotation can affect interop.
		s.tonemapOnce.Do(func() {
			if err := probeTonemap(spec, ffmpeg.ProbeVAAPIOpenCL); err != nil {
				log.Printf("%s-%s: using software HDR tonemapping: %v", s.m.id, s.quality, err)
				return
			}
			s.vaapiOpenCL = true
		})
		spec.VAAPIOpenCL = s.vaapiOpenCL
	}
	// The CUDA graph stays on one device, so probe regardless of transpose mode.
	if ffmpeg.Encoder(spec) == ffmpeg.EncoderNVENC && spec.HDR && s.m.tc.NVENCTonemapCUDA {
		s.tonemapOnce.Do(func() {
			if err := probeTonemap(spec, ffmpeg.ProbeNVENCCUDA); err != nil {
				log.Printf("%s-%s: using software HDR tonemapping: %v", s.m.id, s.quality, err)
				return
			}
			s.nvencCUDA = true
		})
		spec.NVENCCUDA = s.nvencCUDA
	}

	return spec
}
