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

## Thanks
Partially inspired from [go-transcode](https://github.com/m1k1o/go-transcode). The projects use different approaches for segmenting the transcodes.
