#!/bin/sh

set -e

SCRIPT=$(realpath "$0")
SCRIPT_PATH=$(dirname "$SCRIPT")

binExtVar() {
    php -r "require '$SCRIPT_PATH/../lib/Service/BinExt.php'; echo \OCA\Memories\Service\BinExt::$1;"
}

arch() {
    ARCH=$(uname -m)
    if [ "$ARCH" = "x86_64" ]; then
        ARCH="amd64"
    elif [ "$ARCH" = "arm64" ]; then
        ARCH="aarch64"
    fi
    echo $ARCH
}

EXIFTOOL_VER=$(binExtVar EXIFTOOL_VER)
GOVOD_VER=$(binExtVar GOVOD_VER)

# Get exiftool prebuilt binaries
rm -rf bin-ext
mkdir -p bin-ext
cd bin-ext
echo "Getting exiftool $EXIFTOOL_VER"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-amd64-musl"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-amd64-glibc"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-aarch64-musl"
wget -q "https://github.com/pulsejet/exiftool-bin/releases/download/$EXIFTOOL_VER/exiftool-aarch64-glibc"
chmod 755 *

# Get exiftool source
wget -q "https://github.com/exiftool/exiftool/archive/refs/tags/$EXIFTOOL_VER.zip"
unzip -qq "$EXIFTOOL_VER.zip"
mv "exiftool-$EXIFTOOL_VER" exiftool
rm -rf *.zip exiftool/t exiftool/html exiftool/windows_exiftool
chmod 755 exiftool/exiftool

# Get go-vod prebuilt binaries
echo "Getting go-vod $GOVOD_VER"
wget -q "https://github.com/pulsejet/memories/releases/download/go-vod/$GOVOD_VER/go-vod-amd64"
wget -q "https://github.com/pulsejet/memories/releases/download/go-vod/$GOVOD_VER/go-vod-aarch64"
chmod 755 go-vod-*

# Check the version of go-vod is correct internally
if [ "$(./go-vod-$(arch) -version)" != "go-vod $GOVOD_VER" ]; then
    echo "[BUG] go-vod binary version mismatch"
    exit 1
fi

cd ..
