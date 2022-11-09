#!/bin/bash

od=`pwd`

rm -rf /tmp/memories
mkdir -p /tmp/memories
cp -R appinfo l10n img js lib templates COPYING README.md transcoder.yaml exiftest* composer* /tmp/memories

cd /tmp
rm -f memories/appinfo/screencap* memories/js/*.map
rm -rf memories.tar.gz

cd memories
sh "$od/scripts/get-exiftool.sh"
cd ..

tar -zvcf memories.tar.gz memories/
rm -rf memories

cd $od
mv /tmp/memories.tar.gz .
