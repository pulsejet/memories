package bridge

import (
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// dialTimeout bounds the PHP bootstrap until it dials back.
const dialTimeout = 10 * time.Second

// SpawnerConfig configures a Spawner.
type SpawnerConfig struct {
	// CallbackURL builds the PHP bridge endpoint for a file.
	CallbackURL func(fileID int64) string
	// TokenHeader carries the service token on callbacks.
	TokenHeader string
	// TTL is the max lifetime of a worker.
	TTL time.Duration
	// Transport carries callbacks; nil uses http.DefaultTransport.
	Transport http.RoundTripper
}

// Spawner starts workers by calling PHP, which dials back to this go-vod
// with a one-time nonce. The callback request lives as long as its worker.
type Spawner struct {
	cfg    SpawnerConfig
	client http.Client

	// server is this go-vod's address as Nextcloud reaches it. PHP sends
	// it with every request, so dial-backs reach this instance even when
	// several go-vod servers are configured.
	server atomic.Pointer[string]

	mu      sync.Mutex
	pending map[string]chan *Worker
}

func NewSpawner(cfg SpawnerConfig) *Spawner {
	return &Spawner{
		cfg:     cfg,
		client:  http.Client{Transport: cfg.Transport},
		pending: map[string]chan *Worker{},
	}
}

func (s *Spawner) SetServer(addr string) {
	s.server.Store(&addr)
}

// Spawn starts a worker and waits for it to dial back.
func (s *Spawner) Spawn(f File) (*Worker, error) {
	server := s.server.Load()
	if server == nil {
		return nil, errors.New("bridge: go-vod address unknown")
	}

	nonce := rand.Text()
	ch := make(chan *Worker, 1)
	s.mu.Lock()
	s.pending[nonce] = ch
	s.mu.Unlock()

	// Not bound to ctx: the callback outlives the read that spawned it.
	cctx, cancel := context.WithTimeout(context.Background(), dialTimeout+s.cfg.TTL+5*time.Second)
	done := make(chan error, 1)
	go func() {
		defer cancel()
		done <- s.callback(cctx, f, nonce, *server)
	}()

	start := time.Now()
	timer := time.NewTimer(dialTimeout)
	defer timer.Stop()

	var err error
	select {
	case w := <-ch:
		log.Printf("bridge: worker for file %d up in %s", f.ID, time.Since(start).Round(time.Millisecond))
		return w, nil
	case err = <-done:
		if err == nil {
			err = errors.New("php returned without dialing back")
		}
	case <-timer.C:
		err = errors.New("timed out waiting for php to dial back")
	}

	cancel()
	s.mu.Lock()
	delete(s.pending, nonce)
	s.mu.Unlock()
	// A dial-back may have landed just before we gave up.
	select {
	case w := <-ch:
		w.Close()
	default:
	}
	return nil, fmt.Errorf("bridge: spawn for file %d: %w", f.ID, err)
}

func (s *Spawner) callback(ctx context.Context, f File, nonce, server string) error {
	q := url.Values{
		"nonce":  {nonce},
		"server": {server},
		"ttl":    {strconv.Itoa(int(s.cfg.TTL / time.Second))},
	}
	req, err := http.NewRequestWithContext(ctx, "GET", s.cfg.CallbackURL(f.ID)+"?"+q.Encode(), nil)
	if err != nil {
		return err
	}
	req.Header.Set(s.cfg.TokenHeader, f.Token)

	res, err := s.client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(res.Body, 1024))
		return &RemoteError{Status: res.StatusCode, Message: strings.TrimSpace(string(body))}
	}
	return nil
}

// ServeHTTP accepts a PHP dial-back and upgrades it to a worker.
func (s *Spawner) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if !strings.EqualFold(r.Header.Get("Upgrade"), Protocol) {
		http.Error(w, "upgrade required", http.StatusUpgradeRequired)
		return
	}

	// Held until the worker is sent, so Spawn can drain it after giving up.
	s.mu.Lock()
	defer s.mu.Unlock()

	nonce := r.Header.Get(NonceHeader)
	ch, ok := s.pending[nonce]
	if !ok {
		http.Error(w, "unknown nonce", http.StatusForbidden)
		return
	}
	delete(s.pending, nonce)

	conn, rw, err := http.NewResponseController(w).Hijack()
	if err != nil {
		log.Println("bridge: hijack failed:", err)
		return
	}
	conn.SetDeadline(time.Now().Add(time.Second)) // bounds the write under mu
	fmt.Fprintf(rw, ""+
		"HTTP/1.1 101 Switching Protocols\r\n"+
		"Upgrade: %s\r\n"+
		"Connection: Upgrade\r\n"+
		"\r\n", Protocol)
	if err := rw.Flush(); err != nil {
		conn.Close()
		return
	}
	conn.SetDeadline(time.Time{})
	ch <- &Worker{
		conn:    conn,
		r:       rw.Reader,
		expires: time.Now().Add(s.cfg.TTL),
	}
}
