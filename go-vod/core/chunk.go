package core

import "sync"

type Chunk struct {
	id     int
	done   bool
	doneCh chan struct{}
	wake   sync.Once
}

func NewChunk(id int) *Chunk {
	return &Chunk{id: id, doneCh: make(chan struct{})}
}

// signal wakes all waiters at once; done reports completion. Safe to repeat.
func (c *Chunk) signal(done bool) {
	if done {
		c.done = true
	}
	c.wake.Do(func() { close(c.doneCh) })
}
