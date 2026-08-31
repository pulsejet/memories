#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MEMORIES_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
NC_DIR="$(cd "$MEMORIES_DIR/../.." && pwd)"

occ() {
    php "$NC_DIR/occ" "$@"
}

# CI-specific setup
PHP_SERVER_PID=""
if [ -n "$CI" ]; then
    cd "$MEMORIES_DIR"
    npm ci
    npx playwright install
    if [ -f "$NC_DIR/vue.zip" ]; then
        cp "$NC_DIR/vue.zip" .
        unzip -qq -o vue.zip
    fi

    # Speed up loads by disabling unused default apps
    for app in comments contactsinteraction dashboard weather_status user_status updatenotification systemtags files_sharing; do
        occ app:disable "$app" 2>/dev/null || true
    done

    # Setup binary extensions
    cd "$MEMORIES_DIR"
    make bin-ext

    # Enable memories app
    occ app:enable --force memories

    # Run repair steps
    occ maintenance:repair

    # Set debug mode and start dev server
    occ config:system:set --type bool --value true debug
    php -S localhost:8080 -t "$NC_DIR" &
    PHP_SERVER_PID=$!
    export E2E_BASE_URL="http://localhost:8080"
    sleep 2
else
    export E2E_BASE_URL="${E2E_BASE_URL:-http://localhost}"
fi

# Create test user
EPOCH=$(date +%s)
TEST_USER="test-primary-${EPOCH}"
TEST_PASSWORD="password"
export E2E_USER="$TEST_USER"
export E2E_PASSWORD="$TEST_PASSWORD"

CLEANING_UP=0
cleanup() {
    local exit_code=$?
    if [ "$CLEANING_UP" -eq 1 ]; then
        echo "Waiting for cleanup to finish..."
        return
    fi
    CLEANING_UP=1

    # Ignore additional interrupt signals during cleanup
    trap '' INT TERM

    echo "Cleaning up..."
    if [ -n "$TEST_USER" ]; then
        echo "Deleting test user $TEST_USER..."
        occ user:delete "$TEST_USER" 2>/dev/null || true
        rm -rf "$NC_DIR/data/$TEST_USER" 2>/dev/null || true
    fi
    if [ -n "$PHP_SERVER_PID" ]; then
        echo "Stopping PHP dev server (PID $PHP_SERVER_PID)..."
        kill "$PHP_SERVER_PID" 2>/dev/null || true
    fi
    exit $exit_code
}
trap cleanup EXIT INT TERM

echo "Creating test user: $TEST_USER"
OC_PASS="$TEST_PASSWORD" occ user:add --password-from-env --display-name="$TEST_USER" "$TEST_USER"

# Copy local test photo files into test user's directory
echo "Setting up test assets for $TEST_USER..."
USER_FILES_DIR="$NC_DIR/data/$TEST_USER/files"
mkdir -p "$USER_FILES_DIR"
cp -r "$MEMORIES_DIR/e2e/assets/primary/"* "$USER_FILES_DIR/"

# Inherit ownership and permissions from main data directory
chown -R --reference="$NC_DIR/data" "$NC_DIR/data/$TEST_USER" 2>/dev/null || true
chmod -R --reference="$NC_DIR/data" "$NC_DIR/data/$TEST_USER" 2>/dev/null || true
chmod -R u+rwX,g+rwX "$NC_DIR/data/$TEST_USER" 2>/dev/null || true

# Index only test user
occ files:scan "$TEST_USER"
occ memories:index -u "$TEST_USER"

# Set user timeline path
occ user:setting "$TEST_USER" memories timelinePath "/Photos"

# This is needed for the file picker to work correctly
# Who knows why ¯\_(ツ)_/¯
occ user:setting "$TEST_USER" files lastSeenQuotaUsage 0.05

# Run e2e tests
cd "$MEMORIES_DIR"
npm run e2e
