FROM golang:bullseye AS builder
WORKDIR /app
COPY . .
RUN CGO_ENABLED=0 go build -buildvcs=false -ldflags="-s -w"

FROM ubuntu:22.04
WORKDIR /app
ENV DEBIAN_FRONTEND=noninteractive
COPY ./build-ffmpeg.sh .
RUN ./build-ffmpeg.sh

COPY --from=builder /app/go-vod .
EXPOSE 47788
CMD ["/app/go-vod", "config.json"]
