all: dev-setup lint build-js-production test

# Dev env management
dev-setup: clean clean-dev npm-init exiftool php-cs-fixer

exiftool:
	sh scripts/get-exiftool.sh

php-cs-fixer:
	mkdir -p tools/php-cs-fixer
	composer require --working-dir=tools/php-cs-fixer friendsofphp/php-cs-fixer

php-lint:
	tools/php-cs-fixer/vendor/bin/php-cs-fixer fix lib

npm-init:
	npm ci

npm-update:
	npm update

.PHONY: dev-setup exiftool php-cs-fixer php-lint npm-init npm-update

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
