# 1. Misc
bin-ext:
	sh scripts/get-bin-ext.sh

patch-external:
	bash scripts/patch-external.sh

.PHONY: bin-ext patch-external

# 2. PHP
php-init:
	composer install

php-format:
	vendor/bin/php-cs-fixer fix --quiet

php-format-check:
	vendor/bin/php-cs-fixer fix --dry-run --diff

psalm:
	vendor/bin/psalm --no-cache --show-info=true

php-test:
	vendor/bin/phpunit

.PHONY: php-format php-format-check psalm php-test

# 3. Vue
js-init:
	npm ci
	bash scripts/patch-external.sh

js-lint:
	npx vue-tsc --noEmit

PRETTIER_PATHS = src e2e scripts/*.ts webpack*.ts playwright.config.ts

js-format:
	npx prettier --write --log-level=warn $(PRETTIER_PATHS)

js-format-check:
	npx prettier --check $(PRETTIER_PATHS)

build-js:
	npm run dev

build-js-production:
	npm run build

watch-js:
	npm run watch

.PHONY: js-lint js-format js-format-check build-js build-js-production watch-js

# 4. Lint
format: php-format js-format

format-check: php-format-check js-format-check

lint: format psalm js-lint

.PHONY: format format-check lint

# 5. E2E
e2e:
	bash scripts/e2e.sh

e2e-headed:
	npx playwright test --headed

.PHONY: e2e e2e-headed

# 6. Dev & Cleaning
init: bin-ext php-init js-init

dev-setup: clean clean-dev init

clean:
	rm -f js/*

clean-dev:
	rm -rf node_modules

.PHONY: init dev-setup clean clean-dev
