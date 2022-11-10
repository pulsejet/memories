package main

import (
	"fmt"
	"net/http"
)

type Manager struct {
	path  string
	id    string
	close chan string
}

func NewManager(path string, id string, close chan string) *Manager {
	m := &Manager{path: path, id: id, close: close}
	return m
}

func (m *Manager) ServeHTTP(w http.ResponseWriter, r *http.Request, chunk string) {
	fmt.Println("Manager.ServeHTTP", m.id, chunk)
	w.Write([]byte("Hello, world!"))
	w.Write([]byte(chunk))
	w.Write([]byte(m.id))

}
