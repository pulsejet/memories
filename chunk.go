package main

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