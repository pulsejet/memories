#!/bin/sh

set -e

exifver="12.50"

rm -rf exiftool-bin
mkdir -p exiftool-bin
cd exiftool-bin
echo "Getting exiftool $exifver"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-amd64-musl"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-amd64-glibc"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-aarch64-musl"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$exifver/exiftool-aarch64-glibc"
chmod 755 *

wget -q "https://github.com/exiftool/exiftool/archive/refs/tags/$exifver.zip"
unzip -qq "$exifver.zip"
mv "exiftool-$exifver" exiftool
rm -rf *.zip exiftool/t exiftool/html
chmod 755 exiftool/exiftool

govod="0.0.32"
echo "Getting go-vod $govod"
wget -q "https://github.com/pulsejet/go-vod/releases/download/$govod/go-vod-amd64"
wget -q "https://github.com/pulsejet/go-vod/releases/download/$govod/go-vod-aarch64"
chmod 755 go-vod-*

cd ..
