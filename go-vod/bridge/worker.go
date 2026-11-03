package bridge

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"time"
)

// readTimeout bounds one read round trip.
const readTimeout = 15 * time.Second

// File identifies a Nextcloud file and the service token authorizing it.
type File struct {
	ID    int64
	Token string
}

type request struct {
	Close  []int64 `json:"close,omitempty"`
	FileID int64   `json:"fileid,omitempty"`
	Token  string  `json:"token"`
	Offset int64   `json:"offset"`
	Length int     `json:"length"`
}

type response struct {
	Size   int64  `json:"size,omitempty"`
	Length int    `json:"length,omitempty"`
	Status int    `json:"status,omitempty"`
	Error  string `json:"error,omitempty"`
}

// RemoteError is a failure reported by PHP; the worker stays usable.
type RemoteError struct {
	Status  int
	Message string
}

func (e *RemoteError) Error() string {
	return fmt.Sprintf("php %d: %s", e.Status, e.Message)
}

// Worker is one PHP request dialed back over TCP. Not safe for concurrent use.
type Worker struct {
	conn    net.Conn
	r       *bufio.Reader
	expires time.Time
	reads   int

	// Owned by the Pool.
	idleSince time.Time
	fileSizes map[File]int64 // sizes of files PHP opened, holding their lock
	closing   []int64        // file IDs to close with the next request
}

func (w *Worker) expired(now time.Time) bool {
	return !now.Before(w.expires)
}

// ReadAt reads up to len(p) bytes of f at off, returning the count and
// file size, after PHP closes the files in closing. A zero File only
// closes. A *RemoteError leaves the worker usable; others break it.
func (w *Worker) ReadAt(ctx context.Context, f File, p []byte, off int64, closing []int64) (int, int64, error) {
	deadline := time.Now().Add(readTimeout)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	w.conn.SetDeadline(deadline)

	line, _ := json.Marshal(request{
		Close:  closing,
		FileID: f.ID,
		Token:  f.Token,
		Offset: off,
		Length: len(p),
	})
	if _, err := w.conn.Write(append(line, '\n')); err != nil {
		return 0, 0, err
	}
	w.reads++

	line, err := w.r.ReadBytes('\n')
	if err != nil {
		return 0, 0, err
	}
	var res response
	if err := json.Unmarshal(line, &res); err != nil {
		return 0, 0, err
	}
	if res.Status != 0 {
		return 0, 0, &RemoteError{Status: res.Status, Message: res.Error}
	}
	if res.Length < 0 || res.Length > len(p) {
		return 0, 0, fmt.Errorf("bad response length %d", res.Length)
	}
	if _, err := io.ReadFull(w.r, p[:res.Length]); err != nil {
		return 0, 0, err
	}
	return res.Length, res.Size, nil
}

// Close ends the worker; PHP returns when it sees EOF.
func (w *Worker) Close() {
	w.conn.Close()
	log.Printf("bridge: worker closed after %d reads", w.reads)
}
