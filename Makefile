all: dev-setup lint build-js-production test

# Dev env management
dev-setup: clean clean-dev npm-init exiftool install-tools

exiftool:
	sh scripts/get-exiftool.sh

install-tools:
	composer install

php-lint:
	vendor/bin/php-cs-fixer fix lib

psalm:
	vendor/bin/psalm

npm-init:
	npm ci

npm-update:
	npm update

.PHONY: dev-setup exiftool install-tools php-lint psalm npm-init npm-update

# Building
build-js:
	npm run dev

build-js-production:
	rm -f js/* && npm run build

patch-external:
	patch -p1 -N < patches/scroller-perf.patch || true
	patch -p1 -N < patches/scroller-sticky.patch || true

watch-js:
	npm run watch

.PHONY: build-js patch-external watch-js

# Testing
test:
	npm run test

test-watch:
	npm run test:watch

test-coverage:
	npm run test:coverage

.PHONY: test test-watch test-coverage

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

.PHONY: lint lint-fix

# Cleaning
clean:
	rm -f js/*

clean-dev:
	rm -rf node_modules

.PHONY: clean clean-dev
