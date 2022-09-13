#!/bin/bash

od=`pwd`

rm -rf /tmp/memories
cp -R . /tmp/memories

cd /tmp
rm -rf memories.tar.gz
cd memories
git clean -xdf
rm -rf .git .gitignore Makefile package.json package-lock.json phpunit* webpack.js stylelint.config.js tests
rm -rf src js/*.map appinfo/screencap.*
rm -rf *.js .gitignore .npmignore
cd ..

tar -zvcf memories.tar.gz memories/
# openssl dgst -sha512 -sign ~/.nextcloud/certificates/memories.key memories.tar.gz | openssl base64
rm -rf memories

cd $od
mv /tmp/memories.tar.gz .
