# go-vod

Extremely minimal on-demand video transcoding server in go.

## Usage

Note: this package provides bespoke functionality for Memories. As such it is not intended to be used as a library.

You need go and ffmpeg/ffprobe installed

```bash
# Build go-vod binary
CGO_ENABLED=0 go build -ldflags="-s -w"
# Run go-vod locally
NEXTCLOUD_HOST=http://localhost CACHE_DIR=/tmp/go-vod-cache CGO_ENABLED=0  go run -ldflags="-s -w" .
```

Nextcloud proxies every request as `POST /vod` with a JSON body naming the
client, file id and profile (e.g. `index.m3u8`, `720p-000003.m4s`,
`storyboard.vtt`). go-vod fetches the file back from Nextcloud.
`GET /health` reports whether Nextcloud is reachable.

## How it works

On-demand and lazy: playlists render instantly without encoding anything.
The first segment request probes the file once, picks renditions smaller than
the source, and starts ffmpeg at that chunk. Completion is detected from the
playlist ffmpeg prints to stdout; later requests extend a goal window that
pauses (`SIGSTOP`) and resumes (`SIGCONT`) the encoder. Idle videos are torn
down and their temp dirs removed.

```
main.go → api/ → core/ → ffmpeg/
              ↘ config/ ↙
```

- `api/` — HTTP routes, HLS playlists, temp uploads.
- `core/` — sessions: rendition ladder, per-quality transcode supervision,
  chunk wait/serve, idle teardown.
- `ffmpeg/` — probes, argv builder (x264/VA-API/NVENC), segment naming.
- `config/` — server bootstrap load/validate/auto-detect plus the
  per-request transcoding knobs carried in every `/vod` body.

## Thanks
Partially inspired from [go-transcode](https://github.com/m1k1o/go-transcode). The projects use different approaches for segmenting the transcodes.
