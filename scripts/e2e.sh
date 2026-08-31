#!/bin/bash

# ==============================================================================
# Memories E2E Testing Library
# ==============================================================================
#
# Sourcing:
#   source scripts/e2e.sh
#   e2e_setup_user "my-user" "password"     # Set up a test account with assets
#   e2e_cleanup_user "my-user"              # Delete a test account & clean data
#   e2e_main                                # Run full automated test workflow
#
# Fast Iteration Mode:
#   For rapid local development, specify a persistent test account:
#   E2E_USER="my-test-user" make e2e
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

E2E_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MEMORIES_DIR="$(cd "$E2E_SCRIPT_DIR/.." && pwd)"
NC_DIR="$(cd "$MEMORIES_DIR/../.." && pwd)"

occ() {
    php "$NC_DIR/occ" "$@"
}

# Ensure playwright browsers are installed if not already present
e2e_install_browsers() {
    cd "$MEMORIES_DIR"
    if ! node -e 'const { chromium } = require("@playwright/test"); const fs = require("fs"); if (!fs.existsSync(chromium.executablePath())) process.exit(1);' 2>/dev/null; then
        echo "Installing Playwright browsers..."
        npx playwright install --with-deps
    fi
}

# CI-specific setup
e2e_setup_ci() {
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
}

# Check if a user exists
e2e_user_exists() {
    local user="$1"
    occ user:info "$user" >/dev/null 2>&1
}

# Set up test user and assets
e2e_setup_user() {
    local user="${1:-$E2E_USER}"
    local password="${2:-${E2E_PASSWORD:-password}}"

    if [ -z "$user" ]; then
        echo "Error: No user specified for e2e_setup_user" >&2
        return 1
    fi

    if e2e_user_exists "$user"; then
        echo "User '$user' already exists. Skipping account setup and indexing."
        return 0
    fi

    echo "Creating test user: $user"
    OC_PASS="$password" occ user:add --password-from-env --display-name="$user" "$user"

    # Copy local test photo files into test user's directory
    echo "Setting up test assets for $user..."
    local user_files_dir="$NC_DIR/data/$user/files"
    mkdir -p "$user_files_dir"
    cp -r "$MEMORIES_DIR/e2e/assets/primary/"* "$user_files_dir/"

    # Inherit ownership and permissions from main data directory
    chown -R --reference="$NC_DIR/data" "$NC_DIR/data/$user" 2>/dev/null || true
    chmod -R --reference="$NC_DIR/data" "$NC_DIR/data/$user" 2>/dev/null || true
    chmod -R u+rwX,g+rwX "$NC_DIR/data/$user" 2>/dev/null || true

    # Index only test user
    occ files:scan "$user"
    occ memories:index -u "$user"

    # Set user timeline path
    occ user:setting "$user" memories timelinePath "/Photos"

    # Set quota usage for file picker
    occ user:setting "$user" files lastSeenQuotaUsage 0.05
}

# Clean up / delete a test user (and stop dev server if running)
e2e_cleanup_user() {
    local user="${1:-$E2E_USER}"
    if [ -n "$user" ]; then
        if e2e_user_exists "$user"; then
            echo "Deleting test user $user..."
            occ user:delete "$user" 2>/dev/null || true
        fi
        rm -rf "$NC_DIR/data/$user" 2>/dev/null || true
    fi
    if [ -n "$PHP_SERVER_PID" ]; then
        echo "Stopping PHP dev server (PID $PHP_SERVER_PID)..."
        kill "$PHP_SERVER_PID" 2>/dev/null || true
    fi
}

# Main entrypoint orchestrating full execution
e2e_main() {
    local test_args=("$@")
    e2e_install_browsers
    e2e_setup_ci

    # Configure test user and fast iteration flags
    if [ -n "$E2E_USER" ] || [ -n "$TEST_USER" ]; then
        TEST_USER="${E2E_USER:-$TEST_USER}"
        PERSISTENT_USER=1
        CLEANUP_USER="${E2E_CLEANUP_USER:-${E2E_TEARDOWN:-0}}"
    else
        local epoch
        epoch=$(date +%s)
        TEST_USER="test-primary-${epoch}"
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
        if [ "$CLEANUP_USER" -eq 1 ]; then
            e2e_cleanup_user "$TEST_USER"
        fi
        exit $exit_code
    }
    trap cleanup EXIT INT TERM

    e2e_setup_user "$TEST_USER" "$TEST_PASSWORD"

    # Run playwright tests
    cd "$MEMORIES_DIR"
    local run_args=()
    if [ "$PERSISTENT_USER" -eq 1 ]; then
        run_args+=("--grep-invert" "@destructive")
    fi
    if [ ${#test_args[@]} -gt 0 ]; then
        run_args+=("${test_args[@]}")
    fi

    echo "Running Playwright with args: ${run_args[*]}..."
    npx playwright test "${run_args[@]}"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    e2e_main "$@"
fi
