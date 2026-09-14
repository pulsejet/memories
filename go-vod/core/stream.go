package core

import (
	"bufio"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/ffmpeg"
)

// CODEC_H264 matches ffprobe's codec_name for H.264 streams.
const CODEC_H264 = "h264"

// copySeekEpsilon nudges copy seeks past timestamp rounding, so -ss lands
// on the grid keyframe instead of the one before it.
const copySeekEpsilon = 0.001

// chunkWait caps per-request chunk waits; the transcode continues.
var chunkWait = 10 * time.Second

type Stream struct {
	c       *config.Config
	m       *Manager
	quality string
	order   int
	height  int
	width   int
	bitrate int

	goal int

	// mutex guards supervision state only: chunks map, goal, coder,
	// inactive. Serving a file and waiting for completion never hold it.
	mutex  sync.Mutex
	chunks map[int]*Chunk

	coder *exec.Cmd

	inactive int
	stop     chan bool
}

func (s *Stream) init() {
	s.chunks = make(map[int]*Chunk)
	s.stop = make(chan bool, 1)
}

func (s *Stream) Run() {
	// run every 5s
	t := time.NewTicker(5 * time.Second)
	defer t.Stop()

	for {
		select {
		case <-t.C:
			s.mutex.Lock()
			// Prune chunks
			for id := range s.chunks {
				if id < s.goal-s.c.GoalBufferMax {
					s.pruneChunk(id)
				}
			}

			s.inactive++

			// Nothing done for 2 minutes
			if s.inactive >= s.c.StreamIdleTime/5 && s.coder != nil {
				s.clear()
			}
			s.mutex.Unlock()

		case <-s.stop:
			t.Stop()
			s.mutex.Lock()
			s.clear()
			s.mutex.Unlock()
			return
		}
	}
}

func (s *Stream) clear() {
	log.Printf("%s-%s: stopping stream", s.m.id, s.quality)

	for _, chunk := range s.chunks {
		os.Remove(s.getTsPath(chunk.id))
	}

	s.chunks = make(map[int]*Chunk)
	s.goal = 0

	if s.coder != nil {
		s.coder.Process.Kill()
		s.coder.Wait()
		s.coder = nil
	}
}

func (s *Stream) Stop() {
	select {
	case s.stop <- true:
	default:
	}
}

func (s *Stream) ServeChunk(w http.ResponseWriter, id int) error {
	s.mutex.Lock()
	s.inactive = 0
	s.checkGoal(id)

	chunk, ok := s.chunks[id]
	if !ok {
		// Restart unless a nearby chunk shows the transcode is close
		restart := true
		for i := id - 1; i >= id-s.c.LookBehind && i >= 0; i-- {
			if _, ok := s.chunks[i]; ok {
				restart = false
				break
			}
		}
		if restart {
			s.mutex.Unlock()
			s.restartAtChunk(w, id)
			return nil
		}
		chunk = s.createChunk(id)
	}
	s.mutex.Unlock()

	s.waitForChunk(w, chunk)
	return nil
}

func (s *Stream) ServeFullVideo(w http.ResponseWriter, r *http.Request) error {
	args := ffmpeg.MP4Args(s.spec(0, false))

	if s.m.probe.CodecName == CODEC_H264 && s.quality == QUALITY_MAX {
		// try to just send the original file
		http.ServeFile(w, r, s.m.path)
		return nil
	}

	coder := exec.Command(s.c.FFmpeg, args...)
	log.Printf("%s-%s: %s", s.m.id, s.quality, strings.Join(coder.Args[:], " "))

	cmdStdOut, err := coder.StdoutPipe()
	if err != nil {
		log.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	cmdStdErr, err := coder.StderrPipe()
	if err != nil {
		log.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	err = coder.Start()
	if err != nil {
		log.Printf("FATAL: ffmpeg command failed with %s\n", err)
	}
	go s.monitorStderr(cmdStdErr)

	// Write to response
	defer cmdStdOut.Close()
	stdoutReader := bufio.NewReader(cmdStdOut)

	// Write mov headers
	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Server does not support Flusher!",
			http.StatusInternalServerError)
		return nil
	}
	w.Header().Set("Content-Type", "video/mp4")
	w.WriteHeader(http.StatusOK)

	// Write data, flusing every 1MB
	buf := make([]byte, 1024*1024)
	for {
		n, err := stdoutReader.Read(buf)
		if err != nil {
			if err == io.EOF {
				break
			}
			log.Printf("FATAL: ffmpeg command failed with %s\n", err)
			break
		}

		_, err = w.Write(buf[:n])
		if err != nil {
			log.Printf("%s-%s: client closed connection", s.m.id, s.quality)
			log.Println(err)
			break
		}
		flusher.Flush()
	}

	// Terminate ffmpeg process
	coder.Process.Kill()
	coder.Wait()

	return nil
}

func (s *Stream) createChunk(id int) *Chunk {
	if c, ok := s.chunks[id]; ok {
		return c
	} else {
		s.chunks[id] = NewChunk(id)
		return s.chunks[id]
	}
}

func (s *Stream) pruneChunk(id int) {
	delete(s.chunks, id)

	// Remove file
	filename := s.getTsPath(id)
	os.Remove(filename)
}

func (s *Stream) returnChunk(w http.ResponseWriter, id int) {
	filename := s.getTsPath(id)
	f, err := os.Open(filename)
	if err != nil {
		log.Println(err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}
	defer f.Close()
	w.Header().Set("Content-Type", "video/MP2T")
	io.Copy(w, f)
}

func (s *Stream) waitForChunk(w http.ResponseWriter, chunk *Chunk) {
	s.mutex.Lock()
	done := chunk.done
	doneCh := chunk.doneCh
	coder := s.coder
	s.mutex.Unlock()

	if done {
		s.returnChunk(w, chunk.id)
		return
	}

	select {
	case <-doneCh:
	case <-time.After(chunkWait):
	}

	// Re-fetch: a restart may have replaced the chunk object meanwhile.
	// A completed replacement is served instead of failing the request.
	s.mutex.Lock()
	current, ok := s.chunks[chunk.id]
	finished := ok && current.done
	restarted := coder != s.coder
	s.mutex.Unlock()

	if finished {
		s.returnChunk(w, chunk.id)
		return
	}

	// Check if coder was changed
	if restarted {
		w.WriteHeader(http.StatusConflict)
		return
	}

	// Return timeout error
	w.WriteHeader(http.StatusRequestTimeout)
}

func (s *Stream) restartAtChunk(w http.ResponseWriter, id int) {
	s.mutex.Lock()
	// Stop current transcoder
	s.clear()

	chunk := s.createChunk(id) // create first chunk

	// Start the transcoder
	s.goal = id + s.c.GoalBufferMax
	s.transcode(id)
	s.mutex.Unlock()

	s.waitForChunk(w, chunk) // this is also a request
}

func (s *Stream) spec(startAt float64, isHls bool) ffmpeg.Spec {
	return ffmpeg.Spec{
		Bin:     s.c.FFmpeg,
		Input:   s.m.path,
		StartAt: startAt,
		HLS:     isHls,

		Quality:   s.quality,
		Width:     s.width,
		Height:    s.height,
		QF:        s.c.QF,
		FrameRate: s.m.probe.FrameRate,
		Rotation:  s.m.probe.Rotation,
		HDR:       s.m.probe.HDR,
		ChunkSize: s.c.ChunkSize,
		Copy:      s.quality == QUALITY_DIRECT,

		VAAPI:           s.c.VAAPI,
		VAAPILowPower:   s.c.VAAPILowPower,
		NVENC:           s.c.NVENC,
		NVENCTemporalAQ: s.c.NVENCTemporalAQ,
		NVENCScale:      s.c.NVENCScale,

		UseTranspose:     s.c.UseTranspose,
		ForceSwTranspose: s.c.ForceSwTranspose,
		UseGopSize:       s.c.UseGopSize,
	}
}

func (s *Stream) transcode(startId int) {
	var startNumber int
	var startAt float64

	if segs, ok := s.m.CopySegments(); ok && s.quality == QUALITY_DIRECT {
		if startId >= len(segs) {
			startId = len(segs) - 1
		}
		startNumber = startId
		startAt = 0
		if startId > 0 {
			startAt = segs[startId].Start + copySeekEpsilon
		}
	} else if startId > 0 {
		// Start one frame before.
		// This ensures that the keyframes are aligned.
		startNumber = startId - 1
		startAt = float64(startNumber * s.c.ChunkSize)
	}

	args := ffmpeg.SegmentArgs(s.spec(startAt, true), startNumber, s.getTsPath(-1))

	// Start the process
	s.coder = exec.Command(s.c.FFmpeg, args...)

	// Log command, quoting the args as needed
	log.Printf("%s-%s: %s", s.m.id, s.quality, ffmpeg.QuoteForLog(s.coder.Args))

	cmdStdOut, err := s.coder.StdoutPipe()
	if err != nil {
		log.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	cmdStdErr, err := s.coder.StderrPipe()
	if err != nil {
		log.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	err = s.coder.Start()
	if err != nil {
		log.Printf("FATAL: ffmpeg command failed with %s\n", err)
	}

	go s.monitorTranscodeOutput(cmdStdOut)
	go s.monitorStderr(cmdStdErr)
	go s.monitorExit()
}

func (s *Stream) checkGoal(id int) {
	goal := id + s.c.GoalBufferMin
	if goal > s.goal {
		s.goal = id + s.c.GoalBufferMax

		// resume encoding
		if s.coder != nil {
			log.Printf("%s-%s: resuming transcoding", s.m.id, s.quality)
			s.coder.Process.Signal(syscall.SIGCONT)
		}
	}
}

func (s *Stream) getTsPath(id int) string {
	if id == -1 {
		return ffmpeg.SegmentPattern(s.m.tempDir, s.quality)
	}
	return ffmpeg.SegmentPath(s.m.tempDir, s.quality, id)
}

// Separate goroutine
func (s *Stream) monitorTranscodeOutput(cmdStdOut io.ReadCloser) {
	s.mutex.Lock()
	coder := s.coder
	s.mutex.Unlock()

	defer cmdStdOut.Close()
	stdoutReader := bufio.NewReader(cmdStdOut)
	seen := make(map[int]bool)

	for {
		s.mutex.Lock()
		current := s.coder
		s.mutex.Unlock()
		if current != coder {
			break
		}

		line, err := stdoutReader.ReadBytes('\n')
		if err == io.EOF {
			if len(line) == 0 {
				break
			}
		} else if err != nil {
			log.Println(err)
			break
		} else {
			line = line[:(len(line) - 1)]
		}

		l := string(line)

		// Skip repeats: the muxer reprints the whole playlist per segment.
		if segQuality, id, ok := ffmpeg.ParseSegmentLine(l); ok && segQuality == s.quality {
			if seen[id] {
				continue
			}
			seen[id] = true

			// Debug
			log.Printf("%s-%s: recv %s", s.m.id, s.quality, l)

			s.mutex.Lock()
			// The coder has changed; do nothing
			if s.coder == coder {
				s.createChunk(id).signal(true)

				// Check goal satisfied
				if id >= s.goal {
					log.Printf("%s-%s: goal satisfied: %d", s.m.id, s.quality, s.goal)
					s.coder.Process.Signal(syscall.SIGSTOP)
				}
			}
			s.mutex.Unlock()
		}
	}
}

func (s *Stream) monitorStderr(cmdStdErr io.ReadCloser) {
	stderrReader := bufio.NewReader(cmdStdErr)

	for {
		line, err := stderrReader.ReadBytes('\n')
		if err == io.EOF {
			if len(line) == 0 {
				break
			}
		} else if err != nil {
			log.Println(err)
			break
		} else {
			line = line[:(len(line) - 1)]
		}
		log.Println("ffmpeg-error:", string(line))
	}
}

func (s *Stream) monitorExit() {
	// Join the process
	s.mutex.Lock()
	coder := s.coder
	s.mutex.Unlock()
	if coder == nil {
		return
	}

	err := coder.Wait()

	// Try to get exit status
	if exitError, ok := err.(*exec.ExitError); ok {
		exitcode := exitError.ExitCode()
		log.Printf("%s-%s: ffmpeg exited with status: %d", s.m.id, s.quality, exitcode)

		s.mutex.Lock()
		defer s.mutex.Unlock()

		// If error code is >0, there was an error in transcoding
		if exitcode > 0 && s.coder == coder {
			// Wake all outstanding chunks; they re-evaluate
			// (409 on coder change, else 408) instead of waiting out
			for _, chunk := range s.chunks {
				chunk.signal(false)
			}
		}
	}
}
