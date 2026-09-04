# 1. Misc
bin-ext:
	sh scripts/get-bin-ext.sh

patch-external:
	bash scripts/patch-external.sh

.PHONY: bin-ext patch-external

# 2. PHP
php-lint:
	vendor/bin/php-cs-fixer fix

psalm:
	vendor/bin/psalm --no-cache --show-info=true

php-test:
	vendor/bin/phpunit

.PHONY: php-lint psalm php-test

# 3. Vue
js-lint:
	npx vue-tsc --noEmit --skipLibCheck

build-js:
	npm run dev

build-js-production:
	rm -f js/* && npm run build

watch-js:
	npm run watch

.PHONY: js-lint build-js build-js-production watch-js

# 4. Lint
lint: php-lint psalm js-lint

.PHONY: lint

# 5. E2E
e2e:
	bash scripts/e2e.sh

e2e-headed:
	npx playwright test --headed

.PHONY: e2e e2e-headed

# 6. Dev & Cleaning
init: bin-ext
	composer install
	npm ci

dev-setup: clean clean-dev init

clean:
	rm -f js/*

clean-dev:
	rm -rf node_modules

.PHONY: init dev-setup clean clean-dev
