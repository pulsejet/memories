FROM golang:bullseye AS builder
WORKDIR /app
COPY . .
RUN CGO_ENABLED=0 go build -buildvcs=false -ldflags="-s -w"

FROM nvidia/cuda:11.1.1-base-ubuntu20.04
ENV NVIDIA_VISIBLE_DEVICES=all
ENV NVIDIA_DRIVER_CAPABILITIES=all

WORKDIR /app
ENV DEBIAN_FRONTEND=noninteractive
COPY ./build-ffmpeg-nvidia.sh .
RUN ./build-ffmpeg-nvidia.sh

COPY --from=builder /app/go-vod .
EXPOSE 47788
CMD ["/app/go-vod"]
