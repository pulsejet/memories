FROM jellyfin/jellyfin:latest as base

RUN rm -rf /jellyfin && \
    ln -s /usr/lib/jellyfin-ffmpeg/ffmpeg /usr/local/bin/ffmpeg && \
    ln -s /usr/lib/jellyfin-ffmpeg/ffprobe /usr/local/bin/ffprobe

FROM scratch

ENV NVIDIA_DRIVER_CAPABILITIES="compute,video,utility"

COPY --from=base / /
COPY entrypoint.sh /app/entrypoint.sh
WORKDIR /app

EXPOSE 47788

ENTRYPOINT ["/app/entrypoint.sh"]
