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

type Chunk struct {
	id     int
	done   bool
	notifs []chan bool
}

func NewChunk(id int) *Chunk {
	return &Chunk{
		id:     id,
		done:   false,
		notifs: make([]chan bool, 0),
	}
}

type Stream struct {
	c       *Config
	m       *Manager
	quality string
	height  int
	width   int
	bitrate int

	goal int

	mutex  sync.Mutex
	chunks map[int]*Chunk

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
				if id < s.goal-s.c.goalBufferMax {
					s.pruneChunk(id)
				}
			}

			s.inactive++

			// Nothing done for 2 minutes
			if s.inactive >= 24 {
				t.Stop()
				s.clear()
				s.mutex.Unlock()
				return
			}
			s.mutex.Unlock()

		case <-s.stop:
			log.Printf("%s-%s: received stop signal", s.m.id, s.quality)
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
	s.goal = 0

	if s.coder != nil {
		s.coder.Process.Kill()
		s.coder = nil
	}
}

func (s *Stream) Stop() {
	s.stop <- true
}

func (s *Stream) ServeList(w http.ResponseWriter) error {
	WriteM3U8ContentType(w)
	w.Write([]byte("#EXTM3U\n"))
	w.Write([]byte("#EXT-X-VERSION:4\n"))
	w.Write([]byte("#EXT-X-MEDIA-SEQUENCE:0\n"))
	w.Write([]byte("#EXT-X-PLAYLIST-TYPE:VOD\n"))
	w.Write([]byte(fmt.Sprintf("#EXT-X-TARGETDURATION:%d\n", s.c.chunkSize)))

	duration := s.m.probe.Duration.Seconds()
	i := 0
	for duration > 0 {
		size := float64(s.c.chunkSize)
		if duration < size {
			size = duration
		}

		w.Write([]byte(fmt.Sprintf("#EXTINF:%.3f, nodesc\n", size)))
		w.Write([]byte(fmt.Sprintf("%s-%06d.ts\n", s.quality, i)))

		duration -= float64(s.c.chunkSize)
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
	for i := id - 1; i > id-s.c.lookBehind && i >= 0; i-- {
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
	t := time.NewTimer(5 * time.Second)

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
	}
}

func (s *Stream) restartAtChunk(w http.ResponseWriter, id int) {
	// Stop current transcoder
	s.clear()

	chunk := s.createChunk(id) // create first chunk

	// Start the transcoder
	s.goal = id + s.c.goalBufferMax
	s.transcode(id)

	s.waitForChunk(w, chunk) // this is also a request
}

func (s *Stream) transcode(startId int) {
	startAt := float64(startId * s.c.chunkSize)

	args := []string{
		"-loglevel", "warning",
	}

	if startAt > 0 {
		args = append(args, []string{
			"-ss", fmt.Sprintf("%.6f", startAt),
		}...)
	}

	// Input specs
	args = append(args, []string{
		"-autorotate", "0", // consistent behavior
		"-i", s.m.path, // Input file
		"-copyts", // So the "-to" refers to the original TS
	}...)

	// QSV / encoder selection
	VAAPI := os.Getenv("VAAPI") == "1"
	CV := "libx264"
	VF := ""
	if VAAPI {
		CV = "h264_vaapi"
		VF = "scale_vaapi=w=SCALE_WIDTH:h=SCALE_HEIGHT:force_original_aspect_ratio=decrease"
		extra := strings.Split("-hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -hwaccel_output_format vaapi", " ")
		args = append(args, extra...)
	}

	// Scaling for output
	var scale string
	if VAAPI {
		scale = strings.Replace(VF, "SCALE_WIDTH", fmt.Sprintf("%d", s.width), 1)
		scale = strings.Replace(scale, "SCALE_HEIGHT", fmt.Sprintf("%d", s.height), 1)
	} else if s.width >= s.height {
		scale = fmt.Sprintf("scale=-2:%d", s.height)
	} else {
		scale = fmt.Sprintf("scale=%d:-2", s.width)
	}

	// not original (max)
	if s.quality != "max" {
		args = append(args, []string{
			"-vf", scale,
			"-maxrate", fmt.Sprintf("%dk", s.bitrate/1000),
			"-bufsize", fmt.Sprintf("%dK", s.bitrate/3000),
		}...)
	}

	// Output specs
	args = append(args, []string{
		"-c:v", CV,
		"-profile:v", "high",
	}...)

	// Extra args only for x264
	if !VAAPI {
		args = append(args, []string{
			"-preset", "faster",
			"-level:v", "4.0",
		}...)
	}

	// Audio
	args = append(args, []string{
		"-c:a", "aac",
		"-b:a", "192k",
	}...)

	// Segmenting specs
	args = append(args, []string{
		"-avoid_negative_ts", "disabled",
		"-f", "hls",
		"-hls_time", fmt.Sprintf("%d", s.c.chunkSize),
		"-g", fmt.Sprintf("%d", s.c.chunkSize),
		"-hls_segment_type", "mpegts",
		"-start_number", fmt.Sprintf("%d", startId),
		"-hls_segment_filename", s.getTsPath(-1),
		"-",
	}...)

	s.coder = exec.Command(s.c.ffmpeg, args...)
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
}

func (s *Stream) checkGoal(id int) {
	goal := id + s.c.goalBufferMin
	if goal > s.goal {
		s.goal = id + s.c.goalBufferMax

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
		} else {
			if err != nil {
				log.Fatal(err)
			}
			line = line[:(len(line) - 1)]
		}

		l := string(line)

		if strings.Contains(l, ".ts") {
			// Debug
			log.Printf("%s-%s: recv %s", s.m.id, s.quality, l)

			// 1080p-000003.ts
			idx := strings.Split(strings.Split(l, "-")[1], ".")[0]
			id, err := strconv.Atoi(idx)
			if err != nil {
				log.Println("Error parsing chunk id")
			}

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

	// Join the process
	coder.Wait()
}

func (s *Stream) monitorStderr(cmdStdErr io.ReadCloser) {
	stderrReader := bufio.NewReader(cmdStdErr)

	for {
		line, err := stderrReader.ReadBytes('\n')
		if err == io.EOF {
			if len(line) == 0 {
				break
			}
		} else {
			if err != nil {
				log.Fatal(err)
			}
			line = line[:(len(line) - 1)]
		}
		log.Println("ffmpeg-error:", string(line))
	}
}
