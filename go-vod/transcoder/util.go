package transcoder

import "net/http"

func GetQueryString(r *http.Request) string {
	query := r.URL.Query().Encode()
	if query != "" {
		query = "?" + query
	}
	return query
}

func WriteM3U8ContentType(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/x-mpegURL")
}
