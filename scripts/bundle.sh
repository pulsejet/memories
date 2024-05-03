#!/bin/bash

# Fail on error
set -e

# Source directory
src=`pwd`

# Copy source files to temp
rm -rf /tmp/memories
mkdir -p /tmp/memories
cp -R appinfo l10n img js lib templates COPYING README.md CHANGELOG.md exiftest* composer* /tmp/memories

# Cleanup
pushd /tmp
rm -rf memories.tar.gz

# Get exiftool and other binaries
pushd memories
sh "$src/scripts/get-bin-ext.sh"
popd

# Get certificate and key
wget -O memories.crt https://raw.githubusercontent.com/nextcloud/app-certificate-requests/master/memories/memories.crt
echo -e "$APP_PRIVATE_KEY" > memories.key

# Sign app
git clone --recurse-submodules --depth 1 --branch v28.0.5 https://github.com/nextcloud/server nextcloud
php nextcloud/occ integrity:sign-app \
    --privateKey=/tmp/memories.key \
    --certificate=/tmp/memories.crt \
    --path=/tmp/memories

rm -rf nextcloud
rm -rf memories.crt memories.key

# Bundle app
tar --no-same-owner -p -zcf memories.tar.gz memories/
rm -rf memories/

# Move bundle to source
popd
mv /tmp/memories.tar.gz .
