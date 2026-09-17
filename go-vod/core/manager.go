package core

import (
	"context"
	"errors"
	"fmt"
	"hash/fnv"
	"log"
	"math"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
	"golang.org/x/sync/singleflight"
)

const (
	QUALITY_MAX    = "max"
	QUALITY_DIRECT = "direct"
)

// ErrCopyPending means the keyframe probe is still running; answer 409
// so the client fails over and retries later once the grid is warm.
var ErrCopyPending = errors.New("keyframe probe pending")

type Manager struct {
	// c is the shared server config.
	c *config.Config
	// tc is the per-request transcode config.
	tc config.TCfg

	// path is the source file served.
	path string
	// fileid keys the on-disk cache; etag validates it.
	fileid int64
	etag   string
	// tempDir is the per-file segment scratch dir.
	tempDir string
	// id is the registry key and log prefix.
	id string
	// generation is the registry epoch; stale generations are dropped.
	generation uint64
	// idle reports expiry to the registry.
	idle chan IdleEvent
	// inactive counts ticks since any transcode (-1 once destroyed).
	inactive atomic.Int32

	// probe holds the source properties, immutable after creation.
	probe *ProbeVideoData

	// playableCodecs lists browser-playable codecs; immutable after creation.
	playableCodecs []string

	// copyEligible is set from the base probe (playable codec, no rotation)
	// and is immutable after creation. It only says keyframes are worth trying.
	copyEligible bool

	// copyMu guards srcSegments and copyProbed with brief critical
	// sections only. It is never held across the minutes-long ffprobe,
	// or every transcode start would wedge behind direct.m3u8.
	copyMu sync.Mutex
	// srcSegments is the copy grid for direct; empty until EnsureCopySegments.
	srcSegments []ffmpeg.Segment
	// copyProbed marks a finished keyframe attempt, so failures fail fast
	// instead of re-running a minutes-long ffprobe on every request.
	copyProbed bool
	// copySF dedupes concurrent keyframe probes so they share one ffprobe.
	copySF singleflight.Group

	// streams are the renditions by quality ("480p", "direct" / "max").
	streams map[string]*Stream
}

type ProbeVideoData struct {
	Width     int
	Height    int
	Duration  time.Duration
	FrameRate int
	CodecName string
	BitRate   int
	Rotation  int
	HDR       bool
	Audio     ffmpeg.AudioInfo
}

type Rendition struct {
	Quality string
	Width   int
	Height  int
	Bitrate int
	Order   int
}

// NewManagerArgs bundles NewManager construction args.
type NewManagerArgs struct {
	ManagerParams
	C          *config.Config
	Generation uint64
	Idle       chan IdleEvent
}

func NewManager(a NewManagerArgs) (*Manager, error) {
	m := &Manager{
		c:              a.C,
		tc:             a.TConfig,
		path:           a.Path,
		id:             a.StreamID,
		fileid:         a.FileID,
		etag:           a.Etag,
		generation:     a.Generation,
		idle:           a.Idle,
		playableCodecs: a.PlayableCodecs,
	}
	m.streams = make(map[string]*Stream)

	h := fnv.New32a()
	h.Write([]byte(m.path))
	ph := fmt.Sprint(h.Sum32())
	m.tempDir = fmt.Sprintf("%s/%s-%s", m.c.TempDir, m.id, ph)

	// Delete temp dir if exists
	os.RemoveAll(m.tempDir)
	os.MkdirAll(m.tempDir, 0755)

	if err := m.ffprobe(); err != nil {
		return nil, err
	}

	// Possible streams
	m.streams["480p"] = &Stream{c: m.c, m: m, quality: "480p", height: 480, width: 854, bitrate: 400}
	m.streams["720p"] = &Stream{c: m.c, m: m, quality: "720p", height: 720, width: 1280, bitrate: 700}
	m.streams["1080p"] = &Stream{c: m.c, m: m, quality: "1080p", height: 1080, width: 1920, bitrate: 1000}
	m.streams["1440p"] = &Stream{c: m.c, m: m, quality: "1440p", height: 1440, width: 2560, bitrate: 1400}
	m.streams["2160p"] = &Stream{c: m.c, m: m, quality: "2160p", height: 2160, width: 3840, bitrate: 3000}

	// height is our primary dimension for scaling
	// using the probed size, we adjust the width of the stream
	// the smaller dimemension of the output should match the height here
	smDim, lgDim := m.probe.Height, m.probe.Width
	if m.probe.Height > m.probe.Width {
		smDim, lgDim = lgDim, smDim
	}

	// Get the reference bitrate. This is half the current bitrate
	// if the video is H.264, otherwise use the current bitrate.
	refBitrate := int(float64(m.probe.BitRate) / 2.0)
	if m.probe.CodecName != CODEC_H264 {
		refBitrate *= 2
	}

	// If bitrate could not be read, use 10Mbps
	if refBitrate == 0 {
		refBitrate = 10000000
	}

	// Get the multiplier for the reference bitrate.
	// For this get the nearest stream size to the original.
	origPixels := float64(m.probe.Height * m.probe.Width)
	nearestPixels := float64(0)
	nearestStream := ""
	for key, stream := range m.streams {
		streamPixels := float64(stream.height * stream.width)
		if nearestPixels == 0 || math.Abs(origPixels-streamPixels) < math.Abs(origPixels-nearestPixels) {
			nearestPixels = streamPixels
			nearestStream = key
		}
	}

	// Get the bitrate multiplier. This is the ratio of the reference
	// bitrate to the nearest stream bitrate, so we can scale all streams.
	bitrateMultiplier := 1.0
	if nearestStream != "" {
		bitrateMultiplier = float64(refBitrate) / float64(m.streams[nearestStream].bitrate)
	}

	// Only keep streams that are smaller than the video
	for k, stream := range m.streams {
		stream.order = 0

		// scale bitrate using the multiplier
		stream.bitrate = int(math.Ceil(float64(stream.bitrate) * bitrateMultiplier))

		// now store the width of the stream as the larger dimension
		stream.width = int(math.Ceil(float64(lgDim) * float64(stream.height) / float64(smDim)))

		// remove invalid streams
		if (stream.height > smDim || stream.width > lgDim) || // no upscaling; we're not AI
			(float64(stream.bitrate) > float64(m.probe.BitRate)*0.8) || // no more than 80% of original bitrate
			(stream.height%2 != 0 || stream.width%2 != 0) { // no odd dimensions

			// remove stream
			delete(m.streams, k)
			continue
		}
	}

	// Original: stream copy as direct when eligible. Keyframes are not
	// extracted here; direct.m3u8 triggers EnsureCopySegments on demand,
	// so index.m3u8 and 480p etc stay fast.
	if m.IsCopyEligible() {
		m.streams[QUALITY_DIRECT] = &Stream{
			c: m.c, m: m,
			quality: QUALITY_DIRECT,
			height:  m.probe.Height,
			width:   m.probe.Width,
			bitrate: m.probe.BitRate,
			order:   1,
		}
	} else {
		m.streams[QUALITY_MAX] = &Stream{
			c: m.c, m: m,
			quality: QUALITY_MAX,
			height:  m.probe.Height,
			width:   m.probe.Width,
			bitrate: refBitrate,
			order:   1,
		}
	}

	// Start all streams
	for _, stream := range m.streams {
		stream.init()
		go stream.Run()
	}

	log.Printf("%s: new manager for %s", m.id, m.path)

	// Check for inactivity
	go func() {
		t := time.NewTicker(5 * time.Second)
		defer t.Stop()
		for {
			<-t.C

			if m.inactive.Load() == -1 {
				t.Stop()
				return
			}

			m.inactive.Add(1)

			// Check if any stream is active
			for _, stream := range m.streams {
				stream.mutex.Lock()
				active := stream.coder != nil
				stream.mutex.Unlock()
				if active {
					m.inactive.Store(0)
					break
				}
			}

			// Nothing done for 5 minutes
			if m.inactive.Load() >= int32(m.c.ManagerIdleTime/5) {
				t.Stop()
				m.Destroy()
				m.idle <- IdleEvent{ID: m.id, Generation: m.generation}
				return
			}
		}
	}()

	return m, nil
}

// Destroys streams. DOES NOT emit on the close channel.
func (m *Manager) Destroy() {
	log.Printf("%s: destroying manager", m.id)
	m.inactive.Store(-1)

	for _, stream := range m.streams {
		stream.Stop()
	}

	// Delete temp dir
	os.RemoveAll(m.tempDir)

	// Delete file if temp
	freeIfTemp(m.c.TempDir, m.path)
}

func (m *Manager) Renditions() []Rendition {
	out := make([]Rendition, 0, len(m.streams))
	for _, s := range m.streams {
		out = append(out, Rendition{
			Quality: s.quality,
			Width:   s.width,
			Height:  s.height,
			Bitrate: s.bitrate,
			Order:   s.order,
		})
	}
	return out
}

func (m *Manager) Duration() time.Duration {
	return m.probe.Duration
}

func (m *Manager) IsCopyEligible() bool {
	return m.copyEligible
}

func (m *Manager) CopySegments() ([]ffmpeg.Segment, bool) {
	m.copyMu.Lock()
	defer m.copyMu.Unlock()
	if len(m.srcSegments) == 0 {
		return nil, false
	}
	return m.srcSegments, true
}

// CopyProbed reports whether a keyframe attempt has finished.
func (m *Manager) CopyProbed() bool {
	m.copyMu.Lock()
	defer m.copyMu.Unlock()
	return m.copyProbed
}

// TryCacheCopySegments returns the grid from memory or disk cache
// without running ffprobe.
func (m *Manager) TryCacheCopySegments() ([]ffmpeg.Segment, bool) {
	if !m.copyEligible {
		return nil, false
	}
	if segs, ok := m.CopySegments(); ok {
		return segs, true
	}
	keys, ok := LoadCachedKeyframes(m.c.CacheDir(), m.fileid, m.etag)
	if !ok {
		return nil, false
	}
	segs := ffmpeg.CopySegments(keys, m.probe.Duration, m.tc.ChunkSize)
	if len(segs) == 0 {
		return nil, false
	}
	m.copyMu.Lock()
	defer m.copyMu.Unlock()
	if len(m.srcSegments) == 0 {
		m.srcSegments = segs
	} else {
		segs = m.srcSegments
	}
	return segs, true
}

// StartCopyProbeAsync warms the grid in the background; shared across
// retries by singleflight and outlives any single request.
func (m *Manager) StartCopyProbeAsync() {
	go m.EnsureCopySegments()
}

// EnsureCopySegments blocks for keyframe extraction on first direct use.
// Concurrent callers share one probe; CopySegments only takes copyMu
// briefly, so other renditions keep serving while direct probes.
func (m *Manager) EnsureCopySegments() ([]ffmpeg.Segment, bool) {
	if !m.copyEligible {
		return nil, false
	}
	if segs, ok := m.CopySegments(); ok {
		return segs, true
	}
	v, err, _ := m.copySF.Do("keyframes", func() (any, error) {
		if segs, ok := m.CopySegments(); ok {
			return segs, nil
		}
		m.copyMu.Lock()
		probed := m.copyProbed
		m.copyMu.Unlock()
		if probed {
			return nil, fmt.Errorf("keyframe probe already failed for %s", m.path)
		}

		// Long ffprobe with no locks held.
		segs, perr := m.probeCopy()

		m.copyMu.Lock()
		defer m.copyMu.Unlock()
		m.copyProbed = true
		if perr != nil {
			log.Printf("%s: copy disabled for direct: %v", m.id, perr)
			return nil, perr
		}
		m.srcSegments = segs
		return segs, nil
	})
	if err != nil {
		return nil, false
	}
	segs, _ := v.([]ffmpeg.Segment)
	if len(segs) == 0 {
		return nil, false
	}
	return segs, true
}

func (m *Manager) FrameRate() int {
	return m.probe.FrameRate
}

func (m *Manager) HasStream(quality string) bool {
	_, ok := m.streams[quality]
	return ok
}

func (m *Manager) ServeChunk(w http.ResponseWriter, quality string, id int) bool {
	stream, ok := m.streams[quality]
	if !ok {
		return false
	}
	if quality == QUALITY_DIRECT {
		// Trigger the probe; on empty grid the stream re-encodes.
		m.EnsureCopySegments()
	}
	stream.ServeChunk(w, id)
	return true
}

func (m *Manager) ServeFullVideo(w http.ResponseWriter, r *http.Request, quality string) {
	if stream, ok := m.streams[quality]; ok {
		stream.ServeFullVideo(w, r)
		return
	}

	// Fall back to the original: direct copy when eligible, else max.
	if stream, ok := m.streams[QUALITY_DIRECT]; ok {
		stream.ServeFullVideo(w, r)
	} else if stream, ok := m.streams[QUALITY_MAX]; ok {
		stream.ServeFullVideo(w, r)
	} else {
		panic("no original stream")
	}
}

func (m *Manager) ffprobe() error {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	info, err := ffmpeg.Probe(ctx, m.c.FFprobe, m.path)
	if err != nil {
		return err
	}

	m.probe = &ProbeVideoData{
		Width:     info.Width,
		Height:    info.Height,
		Duration:  info.Duration,
		FrameRate: info.FrameRate,
		CodecName: info.CodecName,
		BitRate:   info.BitRate,
		Rotation:  info.Rotation,
		HDR:       info.HDR,
		Audio:     info.Audio,
	}

	// Copy eligibility is cheap (codec + rotation); keyframes come later
	// via EnsureCopySegments on direct.m3u8, so creation stays fast.
	m.copyEligible = m.probe.Rotation == 0 && IsCodecPlayable(m.probe.CodecName, m.playableCodecs)

	return nil
}

// probeCopy extracts keyframes and derives the copy grid.
// Lock-free; EnsureCopySegments publishes the result under copyMu.
func (m *Manager) probeCopy() ([]ffmpeg.Segment, error) {
	cachePath := KeyframeCachePath(m.c.CacheDir(), m.fileid)
	if keys, ok := LoadCachedKeyframes(m.c.CacheDir(), m.fileid, m.etag); ok {
		log.Printf("%s: keyframe cache hit %s", m.id, cachePath)
		segs := ffmpeg.CopySegments(keys, m.probe.Duration, m.tc.ChunkSize)
		if len(segs) == 0 {
			return nil, fmt.Errorf("no keyframe grid for %s", m.path)
		}
		return segs, nil
	}
	log.Printf("%s: keyframe cache miss %s", m.id, cachePath)

	keys, err := ffmpeg.Keyframes(context.Background(), m.c.FFprobe, m.path)
	if err != nil {
		return nil, err
	}
	if err := StoreCachedKeyframes(m.c.CacheDir(), m.fileid, m.etag, keys); err != nil {
		log.Printf("%s: keyframe cache store failed: %v", m.id, err)
	}
	segs := ffmpeg.CopySegments(keys, m.probe.Duration, m.tc.ChunkSize)
	if len(segs) == 0 {
		return nil, fmt.Errorf("no keyframe grid for %s", m.path)
	}
	return segs, nil
}
