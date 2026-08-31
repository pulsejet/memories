#!/bin/bash

# ==============================================================================
# Memories E2E Test Runner
# ==============================================================================
#
# Usage:
#   ./scripts/e2e.sh                      # Standard full run (ephemeral user, all tests, auto-cleanup)
#   make e2e                              # Same as above via Makefile
#
# Fast Iteration Mode:
#   For rapid local development, specify a persistent test account:
#
#   E2E_USER="my-test-user" ./scripts/e2e.sh
#
#   How Fast Iteration Mode works:
#   1. Account Reuse: If the user already exists in Nextcloud, account creation,
#      asset copying, file scanning, and indexing are skipped. If the user does
#      not exist, it is created and initialized once.
#   2. Skip Destructive Tests: State-mutating tests (tagged @destructive,
#      such as file moves and deletions) are skipped so the account's
#      photo structure stays intact across runs.
#   3. Teardown Prevention: The test user and files are NOT deleted on exit.
#
# Optional Environment Variables:
#   E2E_BASE_URL      Nextcloud base URL (default: http://localhost:8080 in CI, http://localhost locally).
#   E2E_USER          Specific test username. Enables Fast Iteration Mode.
#   E2E_PASSWORD      Password for the test user (default: "password").
#   E2E_CLEANUP_USER  Set to "1" to force deleting the user even when E2E_USER is set.
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MEMORIES_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
NC_DIR="$(cd "$MEMORIES_DIR/../.." && pwd)"

occ() {
    php "$NC_DIR/occ" "$@"
}

# Ensure playwright browsers are installed if not already present
cd "$MEMORIES_DIR"
if ! node -e 'const { chromium } = require("@playwright/test"); const fs = require("fs"); if (!fs.existsSync(chromium.executablePath())) process.exit(1);' 2>/dev/null; then
    echo "Installing Playwright browsers..."
    npx playwright install --with-deps
fi

# CI-specific setup
PHP_SERVER_PID=""
if [ -n "$CI" ]; then
    cd "$MEMORIES_DIR"
    npm ci
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

# Configure test user and fast iteration flags
if [ -n "$E2E_USER" ] || [ -n "$TEST_USER" ]; then
    TEST_USER="${E2E_USER:-$TEST_USER}"
    PERSISTENT_USER=1
    CLEANUP_USER="${E2E_CLEANUP_USER:-${E2E_TEARDOWN:-0}}"
else
    EPOCH=$(date +%s)
    TEST_USER="test-primary-${EPOCH}"
    PERSISTENT_USER=0
    CLEANUP_USER=1
fi
TEST_PASSWORD="${E2E_PASSWORD:-password}"
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
    if [ "$CLEANUP_USER" -eq 1 ] && [ -n "$TEST_USER" ]; then
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

# Check if the user already exists
USER_EXISTS=0
if occ user:info "$TEST_USER" >/dev/null 2>&1; then
    USER_EXISTS=1
fi

if [ "$USER_EXISTS" -eq 1 ]; then
    echo "User '$TEST_USER' already exists. Skipping account setup and indexing."
else
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
fi

# Run e2e tests
cd "$MEMORIES_DIR"
if [ "$PERSISTENT_USER" -eq 1 ]; then
    echo "Running non-destructive tests for persistent user '$TEST_USER'..."
    npx playwright test --grep-invert @destructive
else
    echo "Running full test suite for ephemeral user '$TEST_USER'..."
    npm run e2e
fi
