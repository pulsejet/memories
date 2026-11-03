package bridge

import (
	"context"
	"errors"
	"log"
	"maps"
	"slices"
	"sync"
	"time"
)

// SpawnFunc starts a new worker authorized by f.
type SpawnFunc func(f File) (*Worker, error)

var ErrClosed = errors.New("bridge closed")

// PoolConfig sizes a Pool.
type PoolConfig struct {
	MaxWorkers int
	MinWorkers int
	IdleTime   time.Duration
}

// Pool lazily spawns up to MaxWorkers workers and hands each to one read
// at a time. Readers wait when all are busy. Idle workers above
// MinWorkers expire after IdleTime; any worker expires at its TTL.
type Pool struct {
	cfg   PoolConfig
	spawn SpawnFunc

	mu       sync.Mutex
	workers  map[*Worker]struct{} // idle and busy
	idle     []*Worker            // oldest first
	spawning int
	changed  chan struct{}
	closed   bool
	stop     chan struct{}
}

func NewPool(cfg PoolConfig, spawn SpawnFunc) *Pool {
	p := &Pool{
		cfg:     cfg,
		spawn:   spawn,
		workers: map[*Worker]struct{}{},
		changed: make(chan struct{}),
		stop:    make(chan struct{}),
	}
	go p.reaper()
	return p
}

// ReadAt reads through a pooled worker, retrying once on a broken one.
// An empty buf only fetches the size, cached while a worker has f open.
func (p *Pool) ReadAt(ctx context.Context, f File, buf []byte, off int64) (int, int64, error) {
	for attempt := 0; ; attempt++ {
		w, err := p.acquire(ctx, f)
		if err != nil {
			return 0, 0, err
		}

		p.mu.Lock()
		size, cached := w.fileSizes[f]
		closing := w.closing
		if !cached || len(buf) > 0 {
			w.closing = nil
		}
		p.mu.Unlock()
		if cached && len(buf) == 0 {
			p.release(w, false)
			return 0, size, nil
		}

		n, size, err := w.ReadAt(ctx, f, buf, off, closing)
		if n > 0 {
			p.mu.Lock()
			w.fileSizes[f] = size
			p.mu.Unlock()
		}

		var rerr *RemoteError
		broken := err != nil && !errors.As(err, &rerr)
		p.release(w, broken)
		if !broken || attempt > 0 || ctx.Err() != nil {
			return n, size, err
		}
		log.Printf("bridge: retrying read of file %d: %v", f.ID, err)
	}
}

// CloseFile has every worker close the file in PHP, releasing its lock.
// Busy workers send the close with their next read.
func (p *Pool) CloseFile(fileID int64) {
	p.mu.Lock()
	for w := range p.workers {
		n := len(w.fileSizes)
		maps.DeleteFunc(w.fileSizes, func(f File, _ int64) bool {
			return f.ID == fileID
		})
		if len(w.fileSizes) < n {
			w.closing = append(w.closing, fileID)
		}
	}
	p.mu.Unlock()
	go p.flushCloses()
}

// flushCloses sends pending closes to idle workers, which might otherwise
// hold the files until they expire.
func (p *Pool) flushCloses() {
	for {
		p.mu.Lock()
		i := slices.IndexFunc(p.idle, func(w *Worker) bool { return len(w.closing) > 0 })
		if i < 0 {
			p.mu.Unlock()
			return
		}
		w := p.idle[i]
		p.idle = slices.Delete(p.idle, i, i+1)
		closing := w.closing
		w.closing = nil
		p.mu.Unlock()

		_, _, err := w.ReadAt(context.Background(), File{}, nil, 0, closing)
		p.release(w, err != nil)
	}
}

// Stats reports the live and idle worker counts.
func (p *Pool) Stats() (total, idle int) {
	p.mu.Lock()
	defer p.mu.Unlock()
	return len(p.workers) + p.spawning, len(p.idle)
}

func (p *Pool) acquire(ctx context.Context, f File) (*Worker, error) {
	for {
		p.mu.Lock()
		if p.closed {
			p.mu.Unlock()
			return nil, ErrClosed
		}
		if w := p.popIdle(f); w != nil {
			p.mu.Unlock()
			return w, nil
		}
		if len(p.workers)+p.spawning < p.cfg.MaxWorkers {
			p.spawning++
			p.mu.Unlock()
			return p.spawnFor(ctx, f)
		}
		wait := p.changed
		p.mu.Unlock()

		select {
		case <-wait:
		case <-ctx.Done():
			return nil, ctx.Err()
		}
	}
}

// spawnFor starts a worker for a reader. The spawn outlives a cancelled
// reader, and a worker that comes up anyway joins the idle pool.
func (p *Pool) spawnFor(ctx context.Context, f File) (*Worker, error) {
	type result struct {
		w   *Worker
		err error
	}
	done := make(chan result, 1)
	go func() {
		w, err := p.spawn(f)
		p.mu.Lock()
		p.spawning--
		if err != nil {
			p.broadcast()
		} else {
			w.fileSizes = map[File]int64{}
			p.workers[w] = struct{}{}
		}
		p.mu.Unlock()
		done <- result{w, err}
	}()

	select {
	case r := <-done:
		return r.w, r.err
	case <-ctx.Done():
		go func() {
			if r := <-done; r.err == nil {
				p.release(r.w, false)
			}
		}()
		return nil, ctx.Err()
	}
}

// popIdle takes a live idle worker, preferring the newest one that has
// f open in PHP. Expired workers
// are evicted so their slots free up. Caller holds mu.
func (p *Pool) popIdle(f File) *Worker {
	now := time.Now()
	live := p.idle[:0]
	for _, w := range p.idle {
		if w.expired(now) {
			p.drop(w)
		} else {
			live = append(live, w)
		}
	}
	if len(live) < len(p.idle) {
		p.broadcast()
	}
	clear(p.idle[len(live):])
	p.idle = live

	if len(p.idle) == 0 {
		return nil
	}
	pick := len(p.idle) - 1
	for i := pick; i >= 0; i-- {
		if _, ok := p.idle[i].fileSizes[f]; ok {
			pick = i
			break
		}
	}
	w := p.idle[pick]
	p.idle = append(p.idle[:pick], p.idle[pick+1:]...)
	return w
}

func (p *Pool) release(w *Worker, broken bool) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if broken || p.closed || w.expired(time.Now()) {
		p.drop(w)
	} else {
		w.idleSince = time.Now()
		p.idle = append(p.idle, w)
		if len(w.closing) > 0 {
			go p.flushCloses()
		}
	}
	p.broadcast()
}

// drop forgets and closes a worker. Caller holds mu.
func (p *Pool) drop(w *Worker) {
	delete(p.workers, w)
	go w.Close()
}

// broadcast wakes waiters. Caller holds mu.
func (p *Pool) broadcast() {
	close(p.changed)
	p.changed = make(chan struct{})
}

func (p *Pool) reaper() {
	t := time.NewTicker(time.Second)
	defer t.Stop()
	for {
		select {
		case <-p.stop:
			return
		case now := <-t.C:
			p.reap(now)
		}
	}
}

func (p *Pool) reap(now time.Time) {
	p.mu.Lock()
	defer p.mu.Unlock()
	live := p.idle[:0]
	excess := len(p.idle) - p.cfg.MinWorkers
	for _, w := range p.idle {
		if w.expired(now) || (excess > 0 && now.Sub(w.idleSince) >= p.cfg.IdleTime) {
			p.drop(w)
			excess--
		} else {
			live = append(live, w)
		}
	}
	if len(live) < len(p.idle) {
		p.broadcast()
	}
	clear(p.idle[len(live):])
	p.idle = live
}

// Close drops idle workers; busy ones close on release.
func (p *Pool) Close() {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.closed {
		return
	}
	p.closed = true
	close(p.stop)
	for _, w := range p.idle {
		p.drop(w)
	}
	p.idle = nil
	p.broadcast()
}
