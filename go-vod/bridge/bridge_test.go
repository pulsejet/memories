package bridge

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"slices"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/core"
	"github.com/stretchr/testify/require"
)

const testToken = "tok"

func TestProxyRanges(t *testing.T) {
	php, _, proxy := setup(t, PoolConfig{MaxWorkers: 2, MinWorkers: 1, IdleTime: time.Minute}, time.Minute)
	url := proxy.URL(1)
	data := php.data

	get := func(method, rng, token string) (*http.Response, []byte) {
		req, _ := http.NewRequest(method, url, nil)
		req.Header.Set(core.ServiceTokenHeader, token)
		if rng != "" {
			req.Header.Set("Range", rng)
		}
		res, err := http.DefaultClient.Do(req)
		require.NoError(t, err)
		defer res.Body.Close()
		body, err := io.ReadAll(res.Body)
		require.NoError(t, err)
		return res, body
	}
	size := strconv.Itoa(len(data))

	res, body := get("GET", "", testToken)
	require.Equal(t, 200, res.StatusCode)
	require.Equal(t, data, body)

	res, body = get("GET", "bytes=10-19", testToken)
	require.Equal(t, 206, res.StatusCode)
	require.Equal(t, "bytes 10-19/"+size, res.Header.Get("Content-Range"))
	require.Equal(t, data[10:20], body)

	res, body = get("GET", "bytes=100000-", testToken)
	require.Equal(t, 206, res.StatusCode)
	require.Equal(t, data[100000:], body)

	res, body = get("GET", "bytes=-5", testToken)
	require.Equal(t, 206, res.StatusCode)
	require.Equal(t, data[len(data)-5:], body)

	res, _ = get("GET", "bytes="+size+"-", testToken)
	require.Equal(t, 416, res.StatusCode)
	require.Equal(t, "bytes */"+size, res.Header.Get("Content-Range"))

	res, body = get("HEAD", "", testToken)
	require.Equal(t, 200, res.StatusCode)
	require.Equal(t, size, res.Header.Get("Content-Length"))
	require.Empty(t, body)

	res, _ = get("GET", "", "bad")
	require.Equal(t, 403, res.StatusCode)

	require.EqualValues(t, 1, php.callbacks.Load(), "one worker serves sequential reads")
}

func TestPoolMaxWorkers(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 2, IdleTime: time.Minute}, time.Minute)
	php.delay = 20 * time.Millisecond

	var wg sync.WaitGroup
	for range 8 {
		wg.Go(func() {
			buf := make([]byte, 10)
			n, size, err := pool.ReadAt(context.Background(), File{ID: 1, Token: testToken}, buf, 0)
			require.NoError(t, err)
			require.Equal(t, 10, n)
			require.EqualValues(t, len(php.data), size)
		})
	}
	wg.Wait()

	require.EqualValues(t, 2, php.callbacks.Load())
	require.EqualValues(t, 2, php.peak.Load())
	total, idle := pool.Stats()
	require.Equal(t, 2, total)
	require.Equal(t, 2, idle)
}

func TestPoolWaitHonorsContext(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 1, IdleTime: time.Minute}, time.Minute)
	php.delay = 200 * time.Millisecond
	f := File{ID: 1, Token: testToken}

	go pool.ReadAt(context.Background(), f, make([]byte, 1), 0)
	require.Eventually(t, func() bool { return php.busy.Load() == 1 }, time.Second, time.Millisecond)

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()
	_, _, err := pool.ReadAt(ctx, f, make([]byte, 1), 0)
	require.ErrorIs(t, err, context.DeadlineExceeded)
}

func TestPoolIdleExpiry(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 3, MinWorkers: 1, IdleTime: 0}, time.Minute)
	php.delay = 20 * time.Millisecond

	var wg sync.WaitGroup
	for range 3 {
		wg.Go(func() {
			_, _, err := pool.ReadAt(context.Background(), File{ID: 1, Token: testToken}, make([]byte, 1), 0)
			require.NoError(t, err)
		})
	}
	wg.Wait()
	total, _ := pool.Stats()
	require.Equal(t, 3, total)

	pool.reap(time.Now())
	total, idle := pool.Stats()
	require.Equal(t, 1, total, "MinWorkers stay warm")
	require.Equal(t, 1, idle)
	require.Eventually(t, func() bool { return php.live.Load() == 1 }, time.Second, time.Millisecond)
}

func TestPoolTTL(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 1, MinWorkers: 1, IdleTime: time.Minute}, 50*time.Millisecond)
	f := File{ID: 1, Token: testToken}

	_, _, err := pool.ReadAt(context.Background(), f, make([]byte, 1), 0)
	require.NoError(t, err)
	time.Sleep(60 * time.Millisecond)

	// Expired warm workers are not reused, even below MinWorkers.
	_, _, err = pool.ReadAt(context.Background(), f, make([]byte, 1), 0)
	require.NoError(t, err)
	require.EqualValues(t, 2, php.callbacks.Load())

	time.Sleep(60 * time.Millisecond)
	pool.reap(time.Now())
	total, _ := pool.Stats()
	require.Equal(t, 0, total)
	require.Eventually(t, func() bool { return php.live.Load() == 0 }, time.Second, time.Millisecond)
}

func TestPoolRetriesBrokenWorker(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 1, MinWorkers: 1, IdleTime: time.Minute}, time.Minute)
	php.dropAfter = 1
	f := File{ID: 1, Token: testToken}

	for range 3 {
		buf := make([]byte, 4)
		n, _, err := pool.ReadAt(context.Background(), f, buf, 4)
		require.NoError(t, err)
		require.Equal(t, php.data[4:8], buf[:n])
	}
	require.EqualValues(t, 3, php.callbacks.Load())
}

func TestRemoteErrorKeepsWorker(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 1, IdleTime: time.Minute}, time.Minute)

	_, _, err := pool.ReadAt(context.Background(), File{ID: 1, Token: testToken}, make([]byte, 1), 0)
	require.NoError(t, err)

	_, _, err = pool.ReadAt(context.Background(), File{ID: 1, Token: "bad"}, make([]byte, 1), 0)
	var rerr *RemoteError
	require.ErrorAs(t, err, &rerr)
	require.Equal(t, 403, rerr.Status)

	_, _, err = pool.ReadAt(context.Background(), File{ID: 1, Token: testToken}, make([]byte, 1), 0)
	require.NoError(t, err)
	require.EqualValues(t, 1, php.callbacks.Load())
}

func TestSpawnerRejectsUnknownNonce(t *testing.T) {
	sp := NewSpawner(SpawnerConfig{TokenHeader: core.ServiceTokenHeader, TTL: time.Minute})
	req := httptest.NewRequest("GET", "/bridge", nil)
	req.Header.Set("Upgrade", Protocol)
	req.Header.Set(NonceHeader, "nope")
	w := httptest.NewRecorder()
	sp.ServeHTTP(w, req)
	require.Equal(t, http.StatusForbidden, w.Code)
}

func TestSpawnerNeedsServer(t *testing.T) {
	sp := NewSpawner(SpawnerConfig{TokenHeader: core.ServiceTokenHeader, TTL: time.Minute})
	_, err := sp.Spawn(File{ID: 1})
	require.ErrorContains(t, err, "address unknown")
}

func TestSpawnerCallbackFailure(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 1, IdleTime: time.Minute}, time.Minute)
	_, _, err := pool.ReadAt(context.Background(), File{ID: 1, Token: "bad"}, make([]byte, 1), 0)
	require.ErrorContains(t, err, "403")
	total, _ := pool.Stats()
	require.Equal(t, 0, total, "failed spawn frees its slot")
	require.EqualValues(t, 1, php.callbacks.Load())
}

// fakePHP plays the Nextcloud side: on callback it dials back to go-vod
// and serves reads of data until told to quit.
type fakePHP struct {
	data      []byte
	delay     time.Duration
	dropAfter int // close the connection after this many reads (0: never)

	callbacks, reads, live, busy, peak atomic.Int32

	mu     sync.Mutex
	closed []int64
}

func (p *fakePHP) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	p.callbacks.Add(1)
	// Authorize the callback like PHP does: callers need a valid token.
	if r.Header.Get(core.ServiceTokenHeader) != testToken {
		http.Error(w, "bad token", http.StatusForbidden)
		return
	}
	q := r.URL.Query()
	conn, err := net.Dial("tcp", q.Get("server"))
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer conn.Close()
	fmt.Fprintf(conn, "GET /bridge HTTP/1.1\r\nHost: x\r\nConnection: Upgrade\r\nUpgrade: %s\r\n%s: %s\r\n\r\n",
		Protocol, NonceHeader, q.Get("nonce"))
	br := bufio.NewReader(conn)
	res, err := http.ReadResponse(br, nil)
	if err != nil || res.StatusCode != http.StatusSwitchingProtocols {
		http.Error(w, "upgrade failed", http.StatusBadGateway)
		return
	}

	p.live.Add(1)
	defer p.live.Add(-1)
	enc := json.NewEncoder(conn)
	for reads := 0; ; reads++ {
		line, err := br.ReadBytes('\n')
		if err != nil || (p.dropAfter > 0 && reads >= p.dropAfter) {
			return
		}
		var req request
		json.Unmarshal(line, &req)
		p.mu.Lock()
		p.closed = append(p.closed, req.Close...)
		p.mu.Unlock()
		if req.FileID == 0 {
			enc.Encode(response{})
			continue
		}
		p.reads.Add(1)

		for cur := p.busy.Add(1); ; {
			old := p.peak.Load()
			if cur <= old || p.peak.CompareAndSwap(old, cur) {
				break
			}
		}
		time.Sleep(p.delay)
		p.busy.Add(-1)

		if req.Token != testToken {
			enc.Encode(response{Status: 403, Error: "bad token"})
			continue
		}
		end := min(int64(len(p.data)), req.Offset+int64(req.Length))
		data := p.data[min(req.Offset, end):end]
		enc.Encode(response{Size: int64(len(p.data)), Length: len(data)})
		conn.Write(data)
	}
}

func setup(t *testing.T, cfg PoolConfig, ttl time.Duration) (*fakePHP, *Pool, *Proxy) {
	t.Helper()
	php := &fakePHP{data: make([]byte, 3*maxChunk+12345)}
	rand.Read(php.data)
	phpSrv := httptest.NewServer(php)

	sp := NewSpawner(SpawnerConfig{
		CallbackURL: func(id int64) string { return phpSrv.URL + "/bridge/" + strconv.FormatInt(id, 10) },
		TokenHeader: core.ServiceTokenHeader,
		TTL:         ttl,
	})
	vodSrv := httptest.NewServer(sp)
	sp.SetServer(strings.TrimPrefix(vodSrv.URL, "http://"))

	pool := NewPool(cfg, sp.Spawn)
	proxy, err := NewProxy(pool, core.ServiceTokenHeader)
	require.NoError(t, err)

	t.Cleanup(func() {
		proxy.Close()
		pool.Close()
		vodSrv.Close()
		phpSrv.Close()
	})
	return php, pool, proxy
}

func TestPoolCachesSize(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 1, IdleTime: time.Minute}, time.Minute)
	ctx := context.Background()
	f := File{ID: 1, Token: testToken}

	size := func(f File) (int64, error) {
		_, size, err := pool.ReadAt(ctx, f, nil, 0)
		return size, err
	}

	// Not cached until PHP opens the file for a data read.
	n, err := size(f)
	require.NoError(t, err)
	require.EqualValues(t, len(php.data), n)
	_, _, err = pool.ReadAt(ctx, f, make([]byte, 1), 0)
	require.NoError(t, err)
	require.EqualValues(t, 2, php.reads.Load())

	n, err = size(f)
	require.NoError(t, err)
	require.EqualValues(t, len(php.data), n)
	require.EqualValues(t, 2, php.reads.Load(), "served from cache")

	// Other tokens are still authorized by PHP.
	_, err = size(File{ID: 1, Token: "bad"})
	var rerr *RemoteError
	require.ErrorAs(t, err, &rerr)
	require.EqualValues(t, 3, php.reads.Load())
}

func TestPoolCloseFile(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 2, IdleTime: time.Minute}, time.Minute)
	php.delay = 50 * time.Millisecond
	ctx := context.Background()
	closed := func() []int64 {
		php.mu.Lock()
		defer php.mu.Unlock()
		return slices.Clone(php.closed)
	}

	// Two workers, holding files 1 and 2.
	var wg sync.WaitGroup
	for _, id := range []int64{1, 2} {
		wg.Go(func() {
			_, _, err := pool.ReadAt(ctx, File{ID: id, Token: testToken}, make([]byte, 1), 0)
			require.NoError(t, err)
		})
	}
	wg.Wait()

	// One of them gets busy with file 3.
	done := make(chan struct{})
	go func() {
		defer close(done)
		pool.ReadAt(ctx, File{ID: 3, Token: testToken}, make([]byte, 1), 0)
	}()
	require.Eventually(t, func() bool { return php.busy.Load() == 1 }, time.Second, time.Millisecond)

	// The idle worker closes at once, the busy one after its read.
	pool.CloseFile(1)
	pool.CloseFile(2)
	require.Eventually(t, func() bool { return len(closed()) == 1 }, time.Second, time.Millisecond)
	require.EqualValues(t, 1, php.busy.Load(), "busy read still running")
	<-done
	require.Eventually(t, func() bool { return len(closed()) == 2 }, time.Second, time.Millisecond)
	require.ElementsMatch(t, []int64{1, 2}, closed())

	// Sizes are no longer cached.
	reads := php.reads.Load()
	_, _, err := pool.ReadAt(ctx, File{ID: 1, Token: testToken}, nil, 0)
	require.NoError(t, err)
	require.Equal(t, reads+1, php.reads.Load())

	pool.CloseFile(99)
	require.Never(t, func() bool { return len(closed()) > 2 }, 100*time.Millisecond, 10*time.Millisecond)
}

func TestPoolPrefersSameFile(t *testing.T) {
	php, pool, _ := setup(t, PoolConfig{MaxWorkers: 2, IdleTime: time.Minute}, time.Minute)
	php.delay = 20 * time.Millisecond
	read := func(id int64) {
		_, _, err := pool.ReadAt(context.Background(), File{ID: id, Token: testToken}, make([]byte, 1), 0)
		require.NoError(t, err)
	}
	pop := func(id int64) *Worker {
		pool.mu.Lock()
		defer pool.mu.Unlock()
		return pool.popIdle(File{ID: id, Token: testToken})
	}

	// Two workers: one read file 1, the other file 2.
	var wg sync.WaitGroup
	for _, id := range []int64{1, 2} {
		wg.Go(func() { read(id) })
	}
	wg.Wait()

	// The file 1 worker is newest, so it also reads file 3.
	pool.release(pop(1), false)
	read(3)

	// File 2 first, so the newest idle worker never holds the next file.
	for _, id := range []int64{2, 1, 3, 2, 1} {
		w := pop(id)
		pool.release(w, false)
		require.Contains(t, w.fileSizes, File{ID: id, Token: testToken})
	}
}
