package bridge

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"strconv"
	"sync"
	"time"
)

// Reads start small (ffmpeg probes headers, then seeks) and double
// while the client keeps consuming.
const (
	minChunk = 256 << 10
	maxChunk = 2 << 20
)

// sendBuffer caps data queued in the kernel per client. ffmpeg drops the
// connection to seek, discarding it, and each byte costs a PHP read.
const sendBuffer = 128 << 10

type limitedListener struct{ net.Listener }

func (l limitedListener) Accept() (net.Conn, error) {
	c, err := l.Listener.Accept()
	if tc, ok := c.(*net.TCPConn); ok {
		tc.SetWriteBuffer(sendBuffer)
	}
	return c, err
}

var chunkBufs = sync.Pool{
	New: func() any {
		b := make([]byte, maxChunk)
		return &b
	},
}

// Proxy serves files to ffmpeg over loopback HTTP with Range support.
type Proxy struct {
	pool        *Pool
	tokenHeader string
	ln          net.Listener
	srv         *http.Server
}

func NewProxy(pool *Pool, tokenHeader string) (*Proxy, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, err
	}
	p := &Proxy{pool: pool, tokenHeader: tokenHeader, ln: ln}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /file/{id}", p.serveFile)
	p.srv = &http.Server{Handler: mux}
	go p.srv.Serve(limitedListener{ln})
	return p, nil
}

func (p *Proxy) URL(fileID int64) string {
	return fmt.Sprintf("http://%s/file/%d", p.ln.Addr(), fileID)
}

func (p *Proxy) Close() error {
	return p.srv.Close()
}

func (p *Proxy) serveFile(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id <= 0 {
		http.Error(w, "bad file id", http.StatusBadRequest)
		return
	}
	f := File{ID: id, Token: r.Header.Get(p.tokenHeader)}

	// Fetch the size first so auth and other PHP errors keep their status.
	_, size, err := p.pool.ReadAt(r.Context(), f, nil, 0)
	if err != nil {
		var rerr *RemoteError
		if errors.As(err, &rerr) && rerr.Status >= 400 && rerr.Status < 600 {
			http.Error(w, rerr.Message, rerr.Status)
			return
		}
		log.Printf("bridge: file %d: %v", f.ID, err)
		http.Error(w, "bridge read failed", http.StatusBadGateway)
		return
	}

	bufp := chunkBufs.Get().(*[]byte)
	defer chunkBufs.Put(bufp)

	fr := &fileReader{
		ctx:  r.Context(),
		pool: p.pool,
		f:    f,
		size: size,
		buf:  *bufp,
	}
	w.Header().Set("Content-Type", "application/octet-stream")
	http.ServeContent(chunkWriter{w, fr}, r, "", time.Time{}, fr)
}

// chunkWriter writes whole fetched chunks when ServeContent copies from
// its fileReader, instead of through a 32KB copy buffer.
type chunkWriter struct {
	http.ResponseWriter
	r *fileReader
}

func (w chunkWriter) ReadFrom(src io.Reader) (int64, error) {
	lr, ok := src.(*io.LimitedReader)
	if !ok || lr.R != w.r {
		return io.Copy(w.ResponseWriter, src)
	}
	var total int64
	for lr.N > 0 {
		if err := w.r.fill(); err != nil {
			return total, err
		}
		n, err := w.Write(w.r.data[:min(int64(len(w.r.data)), lr.N)])
		w.r.skip(n)
		lr.N -= int64(n)
		total += int64(n)
		if err != nil {
			return total, err
		}
	}
	return total, nil
}

// fileReader is the io.ReadSeeker http.ServeContent reads a file through.
// It fetches chunks that grow while the client keeps reading, since
// ServeContent itself reads in small pieces.
type fileReader struct {
	ctx   context.Context
	pool  *Pool
	f     File
	size  int64
	off   int64  // position of the next Read
	buf   []byte // chunk backing store
	data  []byte // fetched bytes at off
	chunk int
}

func (r *fileReader) Seek(offset int64, whence int) (int64, error) {
	switch whence {
	case io.SeekCurrent:
		offset += r.off
	case io.SeekEnd:
		offset += r.size
	}
	if offset < 0 {
		return 0, errors.New("negative seek")
	}
	if offset != r.off {
		r.off, r.data = offset, nil
	}
	return offset, nil
}

func (r *fileReader) Read(p []byte) (int, error) {
	if err := r.fill(); err != nil {
		return 0, err
	}
	n := copy(p, r.data)
	r.skip(n)
	return n, nil
}

// fill fetches the next chunk if none is buffered.
func (r *fileReader) fill() error {
	if len(r.data) > 0 {
		return nil
	}
	if r.off >= r.size {
		return io.EOF
	}
	r.chunk = min(max(2*r.chunk, minChunk), len(r.buf))
	n, _, err := r.pool.ReadAt(r.ctx, r.f, r.buf[:min(int64(r.chunk), r.size-r.off)], r.off)
	if err == nil && n == 0 {
		err = io.ErrUnexpectedEOF
	}
	if err != nil {
		if r.ctx.Err() == nil {
			log.Printf("bridge: file %d read at %d: %v", r.f.ID, r.off, err)
		}
		return err
	}
	r.data = r.buf[:n]
	return nil
}

func (r *fileReader) skip(n int) {
	r.data = r.data[n:]
	r.off += int64(n)
}
