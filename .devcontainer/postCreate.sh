#!/bin/bash
set -e

echo "Setting up Memories development environment..."

# Install dependencies
make dev-setup

# Fix permissions (scoped to keep this fast on rebuilds)
chown -R www-data:www-data /var/www/html/config /var/www/html/data /var/www/html/custom_apps/memories
git config --global --add safe.directory /var/www/html/custom_apps/memories

OCC="sudo -E -u www-data php /var/www/html/occ"

# Install Nextcloud on first run only (the config volume persists across rebuilds)
if $OCC status 2>/dev/null | grep -q "installed: true"; then
    echo "Nextcloud already installed, running upgrade instead..."
    $OCC upgrade --no-interaction
else
    $OCC maintenance:install \
        --verbose \
        --database=mysql \
        --database-name=nextcloud \
        --database-host=db \
        --database-user=nextcloud \
        --database-pass=nextcloud \
        --admin-user=admin \
        --admin-pass=admin
fi

# Enable debug mode in Nextcloud
$OCC config:system:set --type bool --value true debug --no-interaction

# Enable Memories
$OCC app:enable memories

# Build JavaScript
make build-js
