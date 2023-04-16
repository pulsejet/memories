#!/bin/bash

target="node_modules/react-filerobot-image-editor/lib/utils"

if [ -f $target/loadImageOriginal.js ]; then
    echo "Filerobot is already patched, copying patch again ..."
else
    if [ ! -f $target/loadImage.js ]; then
        echo "Filerobot not installed or patch outdated"
        exit 1
    fi

    echo "Patching filerobot-image-editor ..."
    cp $target/loadImage.js $target/loadImageOriginal.js
fi

cp patches/filerobot-loadImage.js $target/loadImage.js