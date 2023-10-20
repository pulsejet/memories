#!/bin/bash

src=`pwd`

rm -rf /tmp/memories
mkdir -p /tmp/memories
cp -R appinfo l10n img js lib templates COPYING README.md CHANGELOG.md exiftest* composer* /tmp/memories

cd /tmp
rm -rf memories.tar.gz

cd memories
sh "$src/scripts/get-bin-ext.sh"
cd ..

tar --no-same-owner -p -zcf memories.tar.gz memories/
rm -rf memories

cd $src
mv /tmp/memories.tar.gz .
