#!/bin/sh

exifver="12.49"

rm -rf exiftool-bin
mkdir -p exiftool-bin
cd exiftool-bin
wget "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-amd64-musl"
wget "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-amd64-glibc"
wget "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-aarch64-musl"
wget "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-aarch64-glibc"
chmod 755 *
cd ..
