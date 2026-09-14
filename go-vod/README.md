# go-vod

Extremely minimal on-demand video transcoding server in go.

## Usage

Note: this package provides bespoke functionality for Memories. As such it is not intended to be used as a library.

You need go and ffmpeg/ffprobe installed

```bash
CGO_ENABLED=0 go build -ldflags="-s -w"
./go-vod
```

The server exposes all files as HLS streams, at the URL
```
http://localhost:47788/player-id/path/to/file/index.m3u8
```

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

- `api/` — HTTP routes, HLS playlists, temp uploads, config reload.
- `core/` — sessions: rendition ladder, per-quality transcode supervision,
  chunk wait/serve, idle teardown.
- `ffmpeg/` — probes, argv builder (x264/VA-API/NVENC), segment naming.
- `config/` — load, validate, auto-detect.

## Thanks
Partially inspired from [go-transcode](https://github.com/m1k1o/go-transcode). The projects use different approaches for segmenting the transcodes.
