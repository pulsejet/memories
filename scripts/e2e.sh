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
# Optional Environment Variables:
#   E2E_BASE_URL      Nextcloud base URL (default: http://localhost:8080 in CI, http://localhost locally).
#   E2E_USER          Specific test username. Enables Fast Iteration Mode.
#   E2E_PASSWORD      Password for the test user (default: "password").
#   E2E_CLEANUP_USER  Set to "1" to force deleting the user even when E2E_USER is set.
#   NO_PLANET_DB      Set to "1" to skip planet database setup (e.g., on SQLite).
# ==============================================================================

E2E_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MEMORIES_DIR="$(cd "$E2E_SCRIPT_DIR/.." && pwd)"
NC_DIR="$(cd "$MEMORIES_DIR/../.." && pwd)"
E2E_LOGS_DIR="$MEMORIES_DIR/e2e_logs"
E2E_DATASET_CACHE="$MEMORIES_DIR/e2e/.dataset-cache"
REPORT_DIR="$MEMORIES_DIR/playwright-report"

occ() {
    php "$NC_DIR/occ" "$@"
}

e2e_generate_datasets() {
    cd "$MEMORIES_DIR"
    if [ ! -f "$E2E_DATASET_CACHE/primary/for-geo/for-geo-001.jpg" ]; then
        echo "Generating image dataset..."
        npx tsx e2e/dataset-gen.ts

        mkdir -p "$E2E_DATASET_CACHE/primary/for-livephoto"
        cp "$MEMORIES_DIR/tests/assets/apple_h264_boy_01."* "$E2E_DATASET_CACHE/primary/for-livephoto/"
    fi
}

e2e_install_browsers() {
    cd "$MEMORIES_DIR"
    echo "Installing Playwright browsers..."
    npx playwright install chromium --with-deps --only-shell
}

# CI-specific setup
e2e_setup_ci() {
    # Add composer deps for dependencies.
    (cd "$NC_DIR/apps/photos" && composer install --no-dev)
    (cd "$NC_DIR/apps/viewer" && composer install --no-dev)

    # Fresh install of Nextcloud.
    cd "$NC_DIR"
    mkdir data/ # fail on existing
    NC_SETUP_DB_TYPE="${NC_DB_TYPE}"
    if [ "$NC_DB_TYPE" = "mariadb" ]; then
        NC_SETUP_DB_TYPE="mysql"
    fi
    if [ "$NC_SETUP_DB_TYPE" = "sqlite" ]; then
        php occ maintenance:install \
            --verbose \
            --database="$NC_SETUP_DB_TYPE" \
            --admin-user="admin" \
            --admin-pass="password"
    else
        php occ maintenance:install \
            --verbose \
            --database="$NC_SETUP_DB_TYPE" \
            --database-name="nextcloud" \
            --database-host="127.0.0.1" \
            --database-port="$NC_DB_PORT" \
            --database-user="db_user" \
            --database-pass="db_password" \
            --admin-user="admin" \
            --admin-pass="password"
    fi

    # Set up Memories app and dependencies.
    cd "$MEMORIES_DIR"

    # Install JS dependencies.
    npm ci

    # Download external binaries; fast since they are small.
    make bin-ext;

    # Start some tasks in the background (@slow).
    e2e_install_browsers & local browser_pid=$!
    e2e_generate_datasets & local dataset_pid=$!

    # Extract vue build from previous CI step.
    if [ -f "$NC_DIR/vue.zip" ]; then
        unzip -qq -o "$NC_DIR/vue.zip"
    fi

    # Speed up loads by disabling unused default apps
    for app in contactsinteraction dashboard weather_status user_status updatenotification; do
        occ app:disable "$app" 2>/dev/null || true
    done

    # Set up redis cache
    if [ ! -z "$NC_REDIS_PORT" ]; then
        occ config:system:set memcache.distributed --value '\OC\Memcache\Redis'
        occ config:system:set memcache.locking --value '\OC\Memcache\Redis'
        occ config:system:set redis host --value '127.0.0.1'
        occ config:system:set redis password --value ''
        occ config:system:set redis port --type integer --value $NC_REDIS_PORT
    fi

    # Enable apps needed for running the tests.
    occ app:enable --force viewer
    occ app:enable --force photos
    occ app:enable --force memories

    # Run repair steps.
    occ maintenance:repair

    # Enable Nextcloud debug mode.
    occ config:system:set --type bool --value true debug
    occ config:system:set loglevel --type integer --value 0

    # Setup places database unless disabled (@slow).
    if [ -z "$NO_PLANET_DB" ]; then
        occ memories:places-setup --no-interaction --force
    fi

    # Set debug mode and start dev server.
    mkdir -p "$E2E_LOGS_DIR"
    php -S localhost:8080 -t "$NC_DIR" > "$E2E_LOGS_DIR/php_stdout.log" 2> "$E2E_LOGS_DIR/php_stderr.log" &
    echo "$!" > "$E2E_LOGS_DIR/php_server.pid"
    sleep 2 # wait for server to start

    # Wait for background tasks to finish.
    wait "$browser_pid" && wait "$dataset_pid"
}

# Check if a user exists
e2e_user_exists() {
    occ user:info "$1" >/dev/null 2>&1
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
    cp -r "$E2E_DATASET_CACHE/primary/"* "$user_files_dir/"

    # Inherit ownership and permissions from main data directory
    chown -R --reference="$NC_DIR/data" "$NC_DIR/data/$user" 2>/dev/null || true
    chmod -R --reference="$NC_DIR/data" "$NC_DIR/data/$user" 2>/dev/null || true
    chmod -R u+rwX,g+rwX "$NC_DIR/data/$user" 2>/dev/null || true

    # Index only test user
    occ files:scan "$user"
    occ memories:index -u "$user"

    # Set user timeline path
    occ user:setting "$user" memories timelinePath "/for-default"

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
    set -e
    shopt -s globstar

    local test_args=("$@")
    rm -rf "$E2E_LOGS_DIR" "$REPORT_DIR"
    mkdir -p "$E2E_LOGS_DIR" "$REPORT_DIR"

    if [ "$NC_DB_TYPE" = "sqlite" ]; then
        export NO_PLANET_DB=1
    fi

    if [ -n "$CI" ]; then
        e2e_setup_ci
        if [ -f "$E2E_LOGS_DIR/php_server.pid" ]; then
            PHP_SERVER_PID=$(cat "$E2E_LOGS_DIR/php_server.pid")
        fi
        export E2E_BASE_URL="http://localhost:8080"
    else
        export E2E_BASE_URL="${E2E_BASE_URL:-http://localhost}"
    fi

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
        elif [ -n "$PHP_SERVER_PID" ]; then
            echo "Stopping PHP dev server (PID $PHP_SERVER_PID)..."
            kill "$PHP_SERVER_PID" 2>/dev/null || true
        fi

        # Move e2e logs to report
        mv "$E2E_LOGS_DIR" "$REPORT_DIR/"

        # Move nextcloud logs to report
        if [ -n "$CI" ]; then
            LOG_DST="$REPORT_DIR/nextcloud.log"
            mv "$NC_DIR/data/nextcloud.log" "$LOG_DST"

            # Remove excessively verbose messages that are not useful.
            sed -i '/dirty table reads/d' "$LOG_DST"
        fi

        exit $exit_code
    }
    trap cleanup EXIT INT TERM

    e2e_generate_datasets
    e2e_setup_user "$TEST_USER" "$TEST_PASSWORD"

    # Run playwright tests
    cd "$MEMORIES_DIR"
    local run_args=()
    if [ ${#test_args[@]} -gt 0 ]; then
        run_args+=("${test_args[@]}")
    fi

    echo "Running Playwright with args: ${run_args[*]}..."
    set +e
    npx playwright test "${run_args[@]}"
    local PW_EXIT=$?
    set -e

    # Post process video if enabled
    if [ "${E2E_VIDEO:-0}" = "1" ]; then
        echo "Post-processing Playwright videos..."
        npx tsx e2e/video-postprocess.ts

        if [ -n "$CI" ]; then
            mv "$MEMORIES_DIR/playwright-results.mp4" \
                "$MEMORIES_DIR/video-${NC_DB_TYPE}-${PHP_VERSION}-${NC_VERSION}.mp4"
        fi
    fi

    return "$PW_EXIT"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    e2e_main "$@"
fi
