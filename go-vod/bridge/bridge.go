// Package bridge serves Nextcloud file reads to ffmpeg over persistent PHP
// workers, so the PHP bootstrap is paid once per worker instead of per read.
//
// ffmpeg reads a loopback Proxy URL. Each read goes to a pooled Worker: a PHP
// request that dialed back to go-vod (GET /bridge, Upgrade: go-vod-bridge)
// and then serves one read at a time as JSON lines:
//
//	go → php: {"fileid":7,"token":"…","offset":0,"length":262144}
//	go → php: {"close":[7]} closes a file; may also lead a read
//	php → go: {"size":2083898324,"length":262144} then length raw bytes
//	php → go: {"status":403,"error":"…"}
//
// go-vod ends a worker by closing the connection.
package bridge

import (
	"fmt"
	"log"
	"net/http"
	"time"

	"github.com/pulsejet/memories/go-vod/config"
	"github.com/pulsejet/memories/go-vod/core"
)

// Protocol is the HTTP Upgrade token for the PHP dial-back.
const Protocol = "go-vod-bridge"

// NonceHeader pairs a dial-back with the spawn that requested it.
const NonceHeader = "X-Bridge-Nonce"

// Bridge wires the Proxy, Pool and Spawner together.
type Bridge struct {
	spawner *Spawner
	pool    *Pool
	proxy   *Proxy
}

// New panics if the loopback proxy cannot listen.
func New(c *config.Config) *Bridge {
	bc := c.Bridge

	spawner := NewSpawner(SpawnerConfig{
		CallbackURL: c.BridgeURL,
		TokenHeader: core.ServiceTokenHeader,
		TTL:         time.Duration(bc.WorkerTTL) * time.Second,
		Transport:   c.Transport(),
	})

	pool := NewPool(PoolConfig{
		MaxWorkers: bc.MaxWorkers,
		MinWorkers: bc.MinWorkers,
		IdleTime:   time.Duration(bc.IdleTime) * time.Second,
	}, spawner.Spawn)

	proxy, err := NewProxy(pool, core.ServiceTokenHeader)
	if err != nil {
		panic(fmt.Errorf("bridge: %w", err))
	}
	log.Printf("bridge: proxy on %s, up to %d workers", proxy.ln.Addr(), bc.MaxWorkers)

	return &Bridge{spawner: spawner, pool: pool, proxy: proxy}
}

// FileURL is the ffmpeg input for a file, served by the Proxy.
func (b *Bridge) FileURL(fileID int64) string {
	return b.proxy.URL(fileID)
}

// CloseFile has PHP close the file in every worker, releasing its lock.
func (b *Bridge) CloseFile(fileID int64) {
	b.pool.CloseFile(fileID)
}

// SetServer records this go-vod's address as Nextcloud reaches it.
func (b *Bridge) SetServer(addr string) {
	b.spawner.SetServer(addr)
}

// ServeHTTP accepts PHP dial-backs.
func (b *Bridge) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	b.spawner.ServeHTTP(w, r)
}

func (b *Bridge) Close() {
	b.proxy.Close()
	b.pool.Close()
}
