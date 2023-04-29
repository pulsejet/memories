#!/bin/sh

set -e

SCRIPT=$(realpath "$0")
SCRIPT_PATH=$(dirname "$SCRIPT")

binExtVar() {
    php -r "require '$SCRIPT_PATH/../lib/Service/BinExt.php'; echo \OCA\Memories\Service\BinExt::$1;"
}

EXIFTOOL_VER=$(binExtVar EXIFTOOL_VER)
GOVOD_VER=$(binExtVar GOVOD_VER)

rm -rf exiftool-bin
mkdir -p exiftool-bin
cd exiftool-bin
echo "Getting exiftool $EXIFTOOL_VER"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-amd64-musl"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-amd64-glibc"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-aarch64-musl"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-aarch64-glibc"
chmod 755 *

wget -q "https://github.com/exiftool/exiftool/archive/refs/tags/$EXIFTOOL_VER.zip"
unzip -qq "$EXIFTOOL_VER.zip"
mv "exiftool-$EXIFTOOL_VER" exiftool
rm -rf *.zip exiftool/t exiftool/html exiftool/windows_exiftool
chmod 755 exiftool/exiftool

echo "Getting go-vod $GOVOD_VER"
wget -q "https://github.com/pulsejet/go-vod/releases/download/$GOVOD_VER/go-vod-amd64"
wget -q "https://github.com/pulsejet/go-vod/releases/download/$GOVOD_VER/go-vod-aarch64"
chmod 755 go-vod-*

cd ..
