#!/bin/bash

# This script fetches the current version of go-vod from Nextcloud
# to the working directory and runs it. If go-vod exits with a restart
# code, the script will restart it.

# This script is intended to be run by systemd if running on bare metal.

HOST=$NEXTCLOUD_HOST # passed as environment variable

# check if host is set
if [[ -z $HOST ]]; then
    echo "fatal: NEXTCLOUD_HOST is not set"
    exit 1
fi

# add http:// if not present
if [[ ! $HOST == http://* ]] && [[ ! $HOST == https://* ]]; then
    HOST="http://$HOST"
fi

# build URL to fetch binary from Nextcloud
ARCH=$(uname -m)
URL="$HOST/index.php/apps/memories/static/go-vod?arch=$ARCH"

# fetch binary, sleeping 10 seconds between retries
function fetch_binary {
    while true; do
        rm -f go-vod
        curl -L -k -f -m 10 -s -o go-vod $URL
        if [[ $? == 0 ]]; then
            chmod +x go-vod
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
    ./go-vod -version-monitor
    if [[ $? != 12 ]]; then
        break
    fi

    sleep 3 # throttle
done