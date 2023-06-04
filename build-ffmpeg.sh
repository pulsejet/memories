#!/bin/bash

set -e

# apt-get remove -y libva ffmpeg # not needed for Docker

apt-get update
apt-get install -y \
    sudo curl wget \
    autoconf libtool libdrm-dev xorg xorg-dev openbox \
    libx11-dev libgl1-mesa-glx libgl1-mesa-dev \
    xcb libxcb-xkb-dev x11-xkb-utils libx11-xcb-dev \
    libxkbcommon-x11-dev libxcb-dri3-dev \
    cmake git nasm build-essential \
    libx264-dev

mkdir qsvbuild
cd qsvbuild

git clone --depth 1 --branch 2.18.0 https://github.com/intel/libva
cd libva
./autogen.sh
make
sudo make install
sudo ldconfig
cd ..

git clone --depth 1 --branch intel-gmmlib-22.3.5 https://github.com/intel/gmmlib
cd gmmlib
mkdir build && cd build
cmake ..
make -j"$(nproc)"
sudo make install
sudo ldconfig
cd ../..

git clone --depth 1 --branch intel-media-23.1.6 https://github.com/intel/media-driver
mkdir -p build_media
cd build_media
cmake ../media-driver
make -j"$(nproc)"
sudo make install
sudo ldconfig
cd ..

git clone --depth 1 --branch n6.0 https://github.com/FFmpeg/FFmpeg
cd FFmpeg
./configure \
	--enable-nonfree \
	--enable-gpl \
	--enable-libx264

make -j"$(nproc)"
sudo make install
sudo ldconfig
cd ..

cd ..
rm -rf qsvbuild

rm -rf /var/lib/apt/lists/*
