---
description: Configuration for hardware acceleration for transcoding with VA-API and NVENC
---

# Hardware transcoding

Memories supports transcoding acceleration with VA-API and NVENC.

## External Transcoder

If you plan to use hardware transcoding, it may be easier to run the transcoder (go-vod) in a separate docker image containing ffmpeg and hardware acceleration dependencies. For this, you need to clone the [go-vod](https://github.com/pulsejet/go-vod) repository and build the docker image. Then you need to change the vod connect address and mark go-vod as external. The important requirement for running go-vod externally is that the file structure must be exactly same for the target video files.

In the directory with the `docker-compose.yml` file, run,

```bash
git clone https://github.com/pulsejet/go-vod
```

If you are using docker compose, configure a service to start go-vod with the correct devices and filesystem structure. Otherwise, manually start the container with these parameters.

```yaml
# docker-compose.yml

services:
  app:
    image: nextcloud
    restart: always
    depends_on:
      - db
      - redis
    volumes:
      - ncdata:/var/www/html

   go-vod:
     build: ./go-vod
     restart: always
     devices:
      - /dev/dri:/dev/dri
     volumes:
      - ncdata:/var/www/html:ro
```

Finally, point Memories to the external go-vod instance. In the admin interface, set go-vod to external and configure the connect URL to `go-vod:47788`. Alternatively, add the following configuration to `config.php`:

```php
'memories.vod.external' => true,
'memories.vod.connect' => 'go-vod:47788',
```

## VA-API

!!! warning "These instructions are not applicable for external transcoders"

Newer Intel processors come with a feature called QuickSync that can significantly boost transcoding performance (4-5x improvement over x264 is common). QuickSync can be used for hardware accelerated transcoding using the VA-API in ffmpeg.

Note: VA-API acceleration may also work with some AMD GPUs.

To configure VAAPI, you need to have `/dev/dri` available to the Nextcloud instance with the `www-data` in the group owning the drivers. You also need the correct drivers and a compatible version of ffmpeg installed.

Ubuntu:

```bash
sudo apt-get update
sudo apt-get install -y intel-media-va-driver-non-free ffmpeg
```

Alpine:

```bash
apk update
apk add --no-cache bash ffmpeg libva-utils libva-vdpau-driver libva-intel-driver intel-media-driver mesa-va-gallium
```

### Docker installations

If you use Docker, you need to:

1. Pass the `/dev/dri` device to the container. In `docker-compose.yml`:
   ```yaml
   app:
     build: .
     restart: always
     devices:
       - /dev/dri:/dev/dri
   ```
1. Make sure the right drivers are installed. This can be done using a custom Dockerfile, for example

   ```Dockerfile
   FROM nextcloud:latest

   RUN apt-get update && \
       apt-get install -y lsb-release && \
       echo "deb http://ftp.debian.org/debian $(lsb_release -cs) non-free" >> \
          /etc/apt/sources.list.d/intel-graphics.list && \
       apt-get update && \
       apt-get install -y intel-media-va-driver-non-free ffmpeg && \
       rm -rf /var/lib/apt/lists/*

   COPY start.sh /
   CMD /start.sh
   ```

   In `start.sh`,

   ```bash
   #!/bin/bash
   GID=`stat -c "%g" /dev/dri/renderD128`
   groupadd -g $GID render2 || true # sometimes this is needed
   GROUP=`getent group $GID | cut -d: -f1`
   usermod -aG $GROUP www-data

   php-fpm
   ```

1. Check the output of `/tmp/go-vod/<instance-id>.log` if playback has issues

### linuxserver/nextcloud image

You can add the following to the `docker-compose.yml` file to install the drivers:

```yaml
devices:
  - /dev/dri:/dev/dri
environment:
  - DOCKER_MODS=linuxserver/mods:universal-package-install
  - INSTALL_PACKAGES=libva|libva-intel-driver|intel-media-driver|mesa-va-gallium
```

### FFmpeg from source

In some cases, you may need to build the drivers and `ffmpeg` from source. For example, the available version of the media driver for the current debian image used by Nextcloud only supports upto Ice Lake CPUs. [This recipe](https://gist.github.com/pulsejet/4d81c1356703b2c8ba19c1ca9e6f6e50) might be useful.

```Dockerfile
FROM nextcloud:25

# Enable QSV support
SHELL ["/bin/bash", "-c"]
RUN apt-get update && \
    apt-get install -y sudo curl git && \
    rm -rf /var/lib/apt/lists/*
RUN curl https://gist.githubusercontent.com/pulsejet/4d81c1356703b2c8ba19c1ca9e6f6e50/raw/qsv-docker.sh | bash

...
```
