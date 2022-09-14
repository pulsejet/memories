#!/bin/bash

# Build vue
cd apps/memories
make dev-setup
make build-js-production
cd ../..

# Speed up loads
php occ app:disable comments
php occ app:disable contactsinteraction:
php occ app:disable dashboard
php occ app:disable weather_status
php occ app:disable user_status
php occ app:disable updatenotification
php occ app:disable systemtags
php occ app:disable files_sharing

# Enable apps
php occ app:enable --force viewer
php occ app:enable --force memories

# Set debug mode and start dev server
php occ config:system:set --type bool --value true debug
php -S localhost:8080 &

# Get test photo files
cd data/admin/files
wget https://github.com/pulsejet/memories-test/raw/main/Files.zip
unzip Files.zip
cd ../../..

# Index
sudo apt-get install libimage-exiftool-perl -y
php occ files:scan --all
php occ memories:index

# Run e2e tests
cd apps/memories
sudo npx playwright install-deps chromium
npm run e2e
cd ../..
