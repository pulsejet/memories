all: dev-setup lint build-js-production test

# Dev env management
dev-setup: clean clean-dev npm-init bin-ext install-tools

# Download external binaries (exiftool and go-vod) to bin-ext/
bin-ext:
	sh scripts/get-bin-ext.sh

install-tools:
	composer install

php-lint:
	vendor/bin/php-cs-fixer fix

psalm:
	vendor/bin/psalm --no-cache --show-info=true

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
	bash scripts/patch-external.sh

watch-js:
	npm run watch

.PHONY: build-js patch-external watch-js

# Testing
php-test:
	vendor/bin/phpunit

e2e:
	bash scripts/e2e.sh --reporter=list

e2e-headed:
	npx playwright test --headed --reporter=list

.PHONY: php-test e2e e2e-headed


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
