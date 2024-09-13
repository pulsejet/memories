#!/bin/bash

echo "Setting up Memories development environment..."

# Install dependencies
make dev-setup

# Fix permissions
chown -R www-data:www-data /var/www
git config --global --add safe.directory /var/www/html/custom_apps/memories

# Install Nextcloud
sudo -E -u www-data php /var/www/html/occ maintenance:install \
    --verbose \
    --database=mysql \
    --database-name=nextcloud \
    --database-host=db \
    --database-user=nextcloud \
    --database-pass=nextcloud \
    --admin-user=admin \
    --admin-pass=admin

# Enable debug mode in Nextcloud
sudo -E -u www-data php /var/www/html/occ config:system:set --type bool --value true debug

# Enable Memories
sudo -E -u www-data php /var/www/html/occ app:enable memories
sudo -E -u www-data php /var/www/html/occ memories:index

# Build JavaScript
make build-js
