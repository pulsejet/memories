package main

import (
	"bufio"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

const (
	ENCODER_COPY  = "copy"
	ENCODER_X264  = "libx264"
	ENCODER_VAAPI = "h264_vaapi"
	ENCODER_NVENC = "h264_nvenc"

	QUALITY_MAX = "max"
	CODEC_H264  = "h264"
)

type Stream struct {
	c       *Config
	m       *Manager
	quality string
	order   int
	height  int
	width   int
	bitrate int

	goal int

	mutex      sync.Mutex
	chunks     map[int]*Chunk
	seenChunks map[int]bool // only for stdout reader

	coder *exec.Cmd

	inactive int
	stop     chan bool
}

func (s *Stream) Run() {
	// run every 5s
	t := time.NewTicker(5 * time.Second)
	defer t.Stop()

	s.stop = make(chan bool)

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
				t.Stop()
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
		// Delete files
		s.pruneChunk(chunk.id)
	}

	s.chunks = make(map[int]*Chunk)
	s.seenChunks = make(map[int]bool)
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

func (s *Stream) ServeList(w http.ResponseWriter, r *http.Request) error {
	WriteM3U8ContentType(w)
	w.Write([]byte("#EXTM3U\n"))
	w.Write([]byte("#EXT-X-VERSION:4\n"))
	w.Write([]byte("#EXT-X-MEDIA-SEQUENCE:0\n"))
	w.Write([]byte("#EXT-X-PLAYLIST-TYPE:VOD\n"))
	w.Write([]byte(fmt.Sprintf("#EXT-X-TARGETDURATION:%d\n", s.c.ChunkSize)))

	query := GetQueryString(r)

	duration := s.m.probe.Duration.Seconds()
	i := 0
	for duration > 0 {
		size := float64(s.c.ChunkSize)
		if duration < size {
			size = duration
		}

		w.Write([]byte(fmt.Sprintf("#EXTINF:%.3f, nodesc\n", size)))
		w.Write([]byte(fmt.Sprintf("%s-%06d.ts%s\n", s.quality, i, query)))

		duration -= float64(s.c.ChunkSize)
		i++
	}

	w.Write([]byte("#EXT-X-ENDLIST\n"))

	return nil
}

func (s *Stream) ServeChunk(w http.ResponseWriter, id int) error {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	s.inactive = 0
	s.checkGoal(id)

	// Already have this chunk
	if chunk, ok := s.chunks[id]; ok {
		// Chunk is finished, just return it
		if chunk.done {
			s.returnChunk(w, chunk)
			return nil
		}

		// Still waiting on transcoder
		s.waitForChunk(w, chunk)
		return nil
	}

	// Will have this soon enough
	foundBehind := false
	for i := id - 1; i > id-s.c.LookBehind && i >= 0; i-- {
		if _, ok := s.chunks[i]; ok {
			foundBehind = true
		}
	}
	if foundBehind {
		// Make sure the chunk exists
		chunk := s.createChunk(id)

		// Wait for it
		s.waitForChunk(w, chunk)
		return nil
	}

	// Let's start over
	s.restartAtChunk(w, id)
	return nil
}

func (s *Stream) ServeFullVideo(w http.ResponseWriter, r *http.Request) error {
	args := s.transcodeArgs(0)

	if s.m.probe.CodecName == CODEC_H264 && s.quality == QUALITY_MAX {
		// try to just send the original file
		http.ServeFile(w, r, s.m.path)
		return nil
	}

	// Output mov
	args = append(args, []string{
		"-movflags", "frag_keyframe+empty_moov+faststart", "-f", "mov", "pipe:1",
	}...)

	coder := exec.Command(s.c.FFmpeg, args...)
	log.Printf("%s-%s: %s", s.m.id, s.quality, strings.Join(coder.Args[:], " "))

	cmdStdOut, err := coder.StdoutPipe()
	if err != nil {
		fmt.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	cmdStdErr, err := coder.StderrPipe()
	if err != nil {
		fmt.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
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
	w.Header().Set("Content-Type", "video/quicktime")
	w.WriteHeader(http.StatusOK)
	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Server does not support Flusher!",
			http.StatusInternalServerError)
		return nil
	}

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

func (s *Stream) returnChunk(w http.ResponseWriter, chunk *Chunk) {
	// This function is called with lock, but we don't need it
	s.mutex.Unlock()
	defer s.mutex.Lock()

	// Read file and write to response
	filename := s.getTsPath(chunk.id)
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
	if chunk.done {
		s.returnChunk(w, chunk)
		return
	}

	// Add our channel
	notif := make(chan bool)
	chunk.notifs = append(chunk.notifs, notif)
	t := time.NewTimer(10 * time.Second)
	coder := s.coder

	s.mutex.Unlock()

	select {
	case <-notif:
		t.Stop()
	case <-t.C:
	}

	s.mutex.Lock()

	// remove channel
	for i, c := range chunk.notifs {
		if c == notif {
			chunk.notifs = append(chunk.notifs[:i], chunk.notifs[i+1:]...)
			break
		}
	}

	// check for success
	if chunk.done {
		s.returnChunk(w, chunk)
		return
	}

	// Check if coder was changed
	if coder != s.coder {
		w.WriteHeader(http.StatusConflict)
		return
	}

	// Return timeout error
	w.WriteHeader(http.StatusRequestTimeout)
}

func (s *Stream) restartAtChunk(w http.ResponseWriter, id int) {
	// Stop current transcoder
	s.clear()

	chunk := s.createChunk(id) // create first chunk

	// Start the transcoder
	s.goal = id + s.c.GoalBufferMax
	s.transcode(id)

	s.waitForChunk(w, chunk) // this is also a request
}

// Get arguments to ffmpeg
func (s *Stream) transcodeArgs(startAt float64) []string {
	args := []string{
		"-loglevel", "warning",
	}

	if startAt > 0 {
		args = append(args, []string{
			"-ss", fmt.Sprintf("%.6f", startAt),
		}...)
	}

	// encoder selection
	CV := ENCODER_X264

	if s.m.probe.CodecName == CODEC_H264 && s.quality == QUALITY_MAX {
		// Use original stream
		CV = ENCODER_COPY
	} else {
		// Check whether hwaccel should be used
		if s.c.VAAPI {
			CV = ENCODER_VAAPI
			extra := "-hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -hwaccel_output_format vaapi"
			args = append(args, strings.Split(extra, " ")...)
		} else if s.c.NVENC {
			CV = ENCODER_NVENC
			extra := "-hwaccel cuda"
			args = append(args, strings.Split(extra, " ")...)
		}
	}

	// Input specs
	args = append(args, []string{
		"-autorotate", "0", // consistent behavior
		"-i", s.m.path, // Input file
		"-copyts", // So the "-to" refers to the original TS
	}...)

	// Filters
	format := "format=nv12"
	scaler := "scale"
	scalerArgs := make([]string, 0)
	scalerArgs = append(scalerArgs, "force_original_aspect_ratio=decrease")

	if CV == ENCODER_VAAPI {
		format = "format=nv12|vaapi,hwupload"
		scaler = "scale_vaapi"
		scalerArgs = append(scalerArgs, "format=nv12")
	} else if CV == ENCODER_NVENC {
		format = "format=nv12|cuda,hwupload"
		scaler = fmt.Sprintf("scale_%s", s.c.NVENCScale)
		scalerArgs = append(scalerArgs, "passthrough=0")
	}

	// Scale height and width if not max quality
	if s.quality != QUALITY_MAX {
		scalerArgs = append(scalerArgs, fmt.Sprintf("w=%d", s.width))
		scalerArgs = append(scalerArgs, fmt.Sprintf("h=%d", s.height))
	}

	// Apply filter
	if CV != ENCODER_COPY {
		filter := fmt.Sprintf("%s,%s=%s", format, scaler, strings.Join(scalerArgs, ":"))
		args = append(args, []string{"-vf", filter}...)
		args = append(args, []string{"-profile:v", "main"}...)
	}

	// Apply bitrate cap if not max quality
	if s.quality != QUALITY_MAX {
		args = append(args, []string{
			"-maxrate", fmt.Sprintf("%d", s.bitrate),
			"-bufsize", fmt.Sprintf("%d", s.bitrate*2),
		}...)
	}

	// Output specs for video
	args = append(args, []string{
		"-map", "0:v:0",
		"-c:v", CV,
	}...)

	// Device specific output args
	if CV == ENCODER_VAAPI {
		args = append(args, []string{"-global_quality", "25"}...)

		if s.c.VAAPILowPower {
			args = append(args, []string{"-low_power", "1"}...)
		}
	} else if CV == ENCODER_NVENC {
		args = append(args, []string{
			"-preset", "p6",
			"-tune", "ll",
			"-rc", "vbr",
			"-rc-lookahead", "30",
			"-cq", "24",
		}...)

		if s.c.NVENCTemporalAQ {
			args = append(args, []string{"-temporal-aq", "1"}...)
		}
	} else if CV == ENCODER_X264 {
		args = append(args, []string{
			"-preset", "faster",
			"-crf", "24",
		}...)
	}

	// Audio output specs
	args = append(args, []string{
		"-map", "0:a:0?",
		"-c:a", "aac",
		"-ac", "1",
	}...)

	return args
}

func (s *Stream) transcode(startId int) {
	if startId > 0 {
		// Start one frame before
		// This ensures that the keyframes are aligned
		startId--
	}
	startAt := float64(startId * s.c.ChunkSize)

	args := s.transcodeArgs(startAt)

	// Segmenting specs
	args = append(args, []string{
		"-avoid_negative_ts", "disabled",
		"-f", "hls",
		"-hls_time", fmt.Sprintf("%d", s.c.ChunkSize),
		"-force_key_frames", fmt.Sprintf("expr:gte(t,n_forced*%d)", s.c.ChunkSize),
		"-hls_segment_type", "mpegts",
		"-start_number", fmt.Sprintf("%d", startId),
		"-hls_segment_filename", s.getTsPath(-1),
		"-",
	}...)

	s.coder = exec.Command(s.c.FFmpeg, args...)
	log.Printf("%s-%s: %s", s.m.id, s.quality, strings.Join(s.coder.Args[:], " "))

	cmdStdOut, err := s.coder.StdoutPipe()
	if err != nil {
		fmt.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	cmdStdErr, err := s.coder.StderrPipe()
	if err != nil {
		fmt.Printf("FATAL: ffmpeg command stdout failed with %s\n", err)
	}

	err = s.coder.Start()
	if err != nil {
		log.Printf("FATAL: ffmpeg command failed with %s\n", err)
	}

	go s.monitorTranscodeOutput(cmdStdOut, startAt)
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
		return fmt.Sprintf("%s/%s-%%06d.ts", s.m.tempDir, s.quality)
	}
	return fmt.Sprintf("%s/%s-%06d.ts", s.m.tempDir, s.quality, id)
}

// Separate goroutine
func (s *Stream) monitorTranscodeOutput(cmdStdOut io.ReadCloser, startAt float64) {
	s.mutex.Lock()
	coder := s.coder
	s.mutex.Unlock()

	defer cmdStdOut.Close()
	stdoutReader := bufio.NewReader(cmdStdOut)

	for {
		if s.coder != coder {
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

		if strings.Contains(l, ".ts") {
			// 1080p-000003.ts
			idx := strings.Split(strings.Split(l, "-")[1], ".")[0]
			id, err := strconv.Atoi(idx)
			if err != nil {
				log.Println("Error parsing chunk id")
			}

			if s.seenChunks[id] {
				continue
			}
			s.seenChunks[id] = true

			// Debug
			log.Printf("%s-%s: recv %s", s.m.id, s.quality, l)

			func() {
				s.mutex.Lock()
				defer s.mutex.Unlock()

				// The coder has changed; do nothing
				if s.coder != coder {
					return
				}

				// Notify everyone
				chunk := s.createChunk(id)
				if chunk.done {
					return
				}
				chunk.done = true
				for _, n := range chunk.notifs {
					n <- true
				}

				// Check goal satisfied
				if id >= s.goal {
					log.Printf("%s-%s: goal satisfied: %d", s.m.id, s.quality, s.goal)
					s.coder.Process.Signal(syscall.SIGSTOP)
				}
			}()
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
	coder := s.coder
	err := coder.Wait()

	// Try to get exit status
	if exitError, ok := err.(*exec.ExitError); ok {
		exitcode := exitError.ExitCode()
		log.Printf("%s-%s: ffmpeg exited with status: %d", s.m.id, s.quality, exitcode)

		s.mutex.Lock()
		defer s.mutex.Unlock()

		// If error code is >0, there was an error in transcoding
		if exitcode > 0 && s.coder == coder {
			// Notify all outstanding chunks
			for _, chunk := range s.chunks {
				for _, n := range chunk.notifs {
					n <- true
				}
			}
		}
	}
}
