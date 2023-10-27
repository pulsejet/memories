all: dev-setup lint build-js-production test

# Dev env management
dev-setup: clean clean-dev npm-init bin-ext install-tools

bin-ext:
	sh scripts/get-bin-ext.sh

install-tools:
	composer install

php-lint:
	vendor/bin/php-cs-fixer fix

psalm:
	vendor/bin/psalm --no-cache

npm-init:
	npm ci

npm-update:
	npm update

.PHONY: dev-setup bin-ext install-tools php-lint psalm npm-init npm-update

# Building
build-js:
	npm run dev

build-js-production:
	rm -f js/* && npm run build

patch-external:
	patch -p1 -N < patches/scroller-perf.patch || true
	patch -p1 -N < patches/scroller-sticky.patch || true
	patch -p1 -N < patches/plyr-wrap.patch || true

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
