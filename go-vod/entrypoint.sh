#!/bin/bash

# This script fetches the current version of go-vod from Nextcloud
# to the working directory and runs it. If go-vod exits with a restart
# code, the script will restart it.

# This script is intended to be run by systemd if running on bare metal.

# Environment variables
HOST=$NEXTCLOUD_HOST
ALLOW_INSECURE=$NEXTCLOUD_ALLOW_INSECURE

# check if host is set
if [[ -z $HOST ]]; then
    echo "fatal: NEXTCLOUD_HOST is not set"
    exit 1
fi

# check if scheme is set
if [[ ! $HOST == http://* ]] && [[ ! $HOST == https://* ]]; then
    echo "fatal: NEXTCLOUD_HOST must start with http:// or https://"
    exit 1
fi

# check if scheme is http and allow_insecure is not set
if [[ $HOST == http://* ]] && [[ -z $ALLOW_INSECURE ]]; then
    echo "fatal: NEXTCLOUD_HOST is set to http:// but NEXTCLOUD_ALLOW_INSECURE is not set"
    exit 1
fi

# Check if the current working directory is writable
if [ ! -w "." ]; then
    echo "Current working directory is not writable."
    echo "Are you in Docker and non-root (not supported)?"
    exit 1
fi

# build URL to fetch binary from Nextcloud
ARCH=$(uname -m)
URL="$HOST/index.php/apps/memories/static/go-vod?arch=$ARCH"

# set the -k option in curl if allow_insecure is set
EXTRA_CURL_ARGS=""
if [[ $ALLOW_INSECURE == true ]]; then
    EXTRA_CURL_ARGS="$EXTRA_CURL_ARGS -k"
fi

# fetch binary, sleeping 10 seconds between retries
function fetch_binary {
    while true; do
        rm -f go-vod.bin
        curl $EXTRA_CURL_ARGS -L -f -m 10 -s -o go-vod.bin $URL
        if [[ $? == 0 ]]; then
            chmod +x go-vod.bin
            echo "Fetched $URL successfully!"
            break
        fi
        echo "Failed to fetch $URL"
        echo "Are you sure the host is reachable and running Memories v6+?"
        echo "Retrying in 10 seconds..."
        sleep 10
    done
}

# infinite loop
while true; do
    fetch_binary
    ./go-vod.bin -version-monitor
    if [[ $? != 12 ]]; then
        break
    fi

    sleep 3 # throttle
done