FROM golang:bullseye AS builder
WORKDIR /app
COPY . .
RUN CGO_ENABLED=0 go build -buildvcs=false -ldflags="-s -w"

FROM jellyfin/jellyfin:latest as base

RUN rm -rf /jellyfin && \
    ln -s /usr/lib/jellyfin-ffmpeg/ffmpeg /usr/local/bin/ffmpeg && \
    ln -s /usr/lib/jellyfin-ffmpeg/ffprobe /usr/local/bin/ffprobe

FROM scratch

ENV NVIDIA_DRIVER_CAPABILITIES="compute,video,utility"

COPY --from=base / /
COPY --from=builder /app/go-vod .

EXPOSE 47788

ENTRYPOINT ["/go-vod"]