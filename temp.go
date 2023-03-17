package main

import (
	"encoding/json"
	"io/ioutil"
	"log"
	"net/http"
	"os"
	"strings"
)

func (h *Handler) createTempFile(w http.ResponseWriter, r *http.Request, parts []string) (string, error) {
	streamid := parts[0]
	body, err := ioutil.ReadAll(r.Body)
	if err != nil {
		log.Println("Error reading body", err)
		w.WriteHeader(http.StatusInternalServerError)
		return "", err
	}

	// Create temporary file
	file, err := ioutil.TempFile(h.c.TempDir, streamid+"-govod-temp-")
	if err != nil {
		log.Println("Error creating temp file", err)
		w.WriteHeader(http.StatusInternalServerError)
		return "", err
	}
	defer file.Close()

	// Write data to file
	if _, err := file.Write(body); err != nil {
		log.Println("Error writing to temp file", err)
		w.WriteHeader(http.StatusInternalServerError)
		return "", err
	}

	// Return full path to file in JSON
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"path": file.Name()})

	// Return path to file
	return file.Name(), nil
}

func freeIfTemp(path string) {
	if strings.Contains(path, "-govod-temp-") {
		os.Remove(path)
	}
}
