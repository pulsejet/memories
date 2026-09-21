package ffmpeg

import (
	"fmt"
	"strings"
)

// FilterBuilder assembles the -vf filter graph for one rendition,
// tracking frame domain and surface depth so up/download transitions
// are emitted only on real domain changes.
type FilterBuilder struct {
	s      Spec
	cv     string
	filter []string

	// isHwEncoder reports a hardware encode backend.
	isHwEncoder bool
	// isInHw tracks the frame domain: offload decoders emit hardware
	// frames, software decoders system frames.
	isInHw bool
	// bitDepth tracks the current surface depth for download pins:
	// source depth until tonemapping converts to 8-bit SDR.
	bitDepth int
}

// NewFilterBuilder starts a graph for one Spec on its resolved encoder.
func NewFilterBuilder(s Spec, cv string) *FilterBuilder {
	return &FilterBuilder{
		s:           s,
		cv:          cv,
		isHwEncoder: cv == EncoderVAAPI || cv == EncoderNVENC,
		isInHw:      cv == EncoderVAAPI || cv == EncoderNVENC,
		bitDepth:    s.BitDepth,
	}
}

func (b *FilterBuilder) append(filters ...string) {
	b.filter = append(b.filter, filters...)
}

// render joins the graph into the single -vf argument.
func (b *FilterBuilder) render() string {
	return strings.Join(b.filter, ",")
}

// hwupload re-enters the encode domain via normalize,
// emitting only when leaving system frames for a HW encoder.
func (b *FilterBuilder) hwupload() {
	if !b.isInHw && b.isHwEncoder {
		b.normalize()
		b.isInHw = true
	}
}

// hwdownload moves frames to the CPU, pinning the tracked depth,
// emitting only when leaving the encode domain.
func (b *FilterBuilder) hwdownload() {
	if b.isInHw {
		b.append("hwdownload", fmt.Sprintf("format=%s", downloadPin(b.bitDepth)))
		b.isInHw = false
	}
}

// normalize enters the encode domain: 8-bit convert plus hardware upload.
// Emitted without checking isInHw: it also covers decoders that fell back
// to software frames.
func (b *FilterBuilder) normalize() {
	switch b.cv {
	case EncoderVAAPI:
		b.append("format=nv12|vaapi", "hwupload")
	case EncoderNVENC:
		b.append("format=nv12|cuda", "hwupload")
	default:
		b.append("format=nv12")
	}
}

// scaleDims is the shared aspect-preserving downscale,
// empty at QualityMax.
func (b *FilterBuilder) scaleDims() []string {
	if b.s.Quality == QualityMax {
		return nil
	}
	maxDim := max(b.s.Width, b.s.Height)
	return []string{
		fmt.Sprintf("w=%d", maxDim),
		fmt.Sprintf("h=%d", maxDim),
	}
}

// scaleEncode renders the downscale in the encode domain: hardware per
// backend at the given format, software otherwise.
func (b *FilterBuilder) scaleEncode(format string) {
	var scalerName string
	switch b.cv {
	case EncoderX264:
		scalerName = "scale"
	case EncoderVAAPI:
		scalerName = "scale_vaapi"
	case EncoderNVENC:
		scalerName = fmt.Sprintf("scale_%s", b.s.NVENCScale)
	default:
		panic("unknown encoder: " + b.cv)
	}

	// Base args always preserve aspect ratio.
	args := []string{
		"force_original_aspect_ratio=decrease",
	}

	// Convert to the encode format, except on software where the
	// scaler must preserve source depth (e.g. HDR before tonemap).
	if b.cv != EncoderX264 {
		args = append(args, fmt.Sprintf("format=%s", format))
	}

	// The CUDA scaler needs an explicit passthrough opt-out.
	if b.cv == EncoderNVENC && b.s.NVENCScale == "cuda" {
		args = append(args, "passthrough=0")
	}

	// Downscale dimensions, unless at max quality.
	args = append(args, b.scaleDims()...)

	// Render the final filter.
	b.append(fmt.Sprintf("%s=%s", scalerName, strings.Join(args, ":")))
}

// scaleCpu renders the software downscale preserving source depth.
func (b *FilterBuilder) scaleCpu() {
	args := []string{
		"force_original_aspect_ratio=decrease",
	}
	args = append(args, b.scaleDims()...)
	b.append(fmt.Sprintf("scale=%s", strings.Join(args, ":")))
}

// tonemapCpu maps HDR to SDR with hable; zscale supplies linear light.
// Pure CPU middle: no scale, no up/download.
func (b *FilterBuilder) tonemapCpu() {
	b.append(
		"zscale=t=linear:npl=100",
		"format=gbrpf32le",
		"zscale=p=bt709",
		"tonemap=hable",
		"zscale=t=bt709:m=bt709:range=tv",
	)
}

// tonemapOpenCL maps HDR to SDR on the GPU. Frames stay on VAAPI,
// hence the interop maps around it.
func (b *FilterBuilder) tonemapOpenCL() {
	b.append(
		"hwmap=derive_device=opencl",
		"tonemap_opencl=tonemap=hable:format=nv12:primaries=bt709:transfer=bt709:matrix=bt709:range=tv",
		"hwmap=derive_device=vaapi:reverse=1",
	)
}

// transposePlan resolves Rotation to filters; sw reports CPU transpose,
// forced around broken hardware filters and always for transpose_cuda.
func (b *FilterBuilder) transposePlan() (filters []string, sw bool) {
	s, cv := b.s, b.cv
	if !s.UseTranspose {
		return nil, false
	}
	transposer := "transpose"
	switch cv {
	case EncoderVAAPI:
		transposer = "transpose_vaapi"
	case EncoderNVENC:
		transposer = fmt.Sprintf("transpose_%s", s.NVENCScale)
	}
	if transposer != "transpose" && (s.ForceSwTranspose || transposer == "transpose_cuda") {
		transposer = "transpose"
		sw = true
	}
	switch s.Rotation {
	case -90:
		filters = []string{transposer + "=1"}
	case 90:
		filters = []string{transposer + "=2"}
	case 180, -180:
		filters = []string{transposer + "=1", transposer + "=1"}
	}
	return filters, sw
}

// downloadPin maps sample depth to the hw surface format.
func downloadPin(depth int) string {
	switch depth {
	case 12:
		return "p012le"
	case 14, 16:
		return "p016le"
	case 10:
		return "p010le"
	default:
		return "nv12"
	}
}
