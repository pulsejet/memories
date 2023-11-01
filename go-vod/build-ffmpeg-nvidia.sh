#!/bin/bash

set -e

# This script is intended for bare-metal installations.
# It builds ffmpeg and NVENC drivers from source.

apt-get remove -y ffmpeg

apt-get update
apt-get install -y \
    sudo curl wget \
    autoconf libtool libdrm-dev xorg xorg-dev openbox \
    libx11-dev libgl1-mesa-glx libgl1-mesa-dev \
    xcb libxcb-xkb-dev x11-xkb-utils libx11-xcb-dev \
    libxkbcommon-x11-dev libxcb-dri3-dev \
    cmake git nasm build-essential \
    libx264-dev \
    libffmpeg-nvenc-dev clang

git clone --branch sdk/11.1 https://git.videolan.org/git/ffmpeg/nv-codec-headers.git
cd nv-codec-headers
sudo make install
cd ..

git clone --depth 1 --branch n5.1.3 https://github.com/FFmpeg/FFmpeg
cd FFmpeg
./configure \
	--enable-nonfree \
	--enable-gpl \
	--enable-libx264 \
        --enable-nvenc \
        --enable-ffnvcodec \
        --enable-cuda-llvm

make -j"$(nproc)"
sudo make install
sudo ldconfig
cd ..
