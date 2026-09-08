package transcoder

import (
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
)

func (h *Handler) createTempFile(w http.ResponseWriter, r *http.Request, parts []string) (string, error) {
	streamid := parts[0]

	// Create temporary file
	file, err := os.CreateTemp(h.c.TempDir, streamid+"-govod-temp-")
	if err != nil {
		log.Println("Error creating temp file", err)
		w.WriteHeader(http.StatusInternalServerError)
		return "", err
	}
	defer file.Close()

	// Stream request body directly to file to avoid holding whole blob in RAM
	if _, err := io.Copy(file, r.Body); err != nil {
		log.Println("Error writing to temp file", err)
		file.Close()
		os.Remove(file.Name())
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
