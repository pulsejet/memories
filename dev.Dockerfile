FROM golang:bullseye AS builder
WORKDIR /app
COPY . .
RUN CGO_ENABLED=0 go build -buildvcs=false -ldflags="-s -w"

FROM linuxserver/ffmpeg:latest

COPY --from=builder /app/go-vod .

EXPOSE 47788

ENTRYPOINT ["/go-vod"]