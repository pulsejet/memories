all: dev-setup lint build-js-production test

# Dev env management
dev-setup: clean clean-dev npm-init exiftool php-cs-fixer

exiftool:
	sh scripts/get-exiftool.sh

php-cs-fixer:
	mkdir --parents tools/php-cs-fixer
	composer require --working-dir=tools/php-cs-fixer friendsofphp/php-cs-fixer

php-lint:
	tools/php-cs-fixer/vendor/bin/php-cs-fixer fix lib

npm-init:
	npm ci

npm-update:
	npm update

# Building
build-js:
	npm run dev

build-js-production:
	rm -f js/* && npm run build

patch-external:
	patch -p1 < patches/scroller.patch

watch-js:
	npm run watch

# Testing
test:
	npm run test

test-watch:
	npm run test:watch

test-coverage:
	npm run test:coverage

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

# Cleaning
clean:
	rm -f js/*

clean-dev:
	rm -rf node_modules

