# 1. Misc
bin-ext:
	sh scripts/get-bin-ext.sh

patch-external:
	bash scripts/patch-external.sh

.PHONY: bin-ext patch-external

# 2. PHP
php-init:
	composer install

php-lint:
	vendor/bin/php-cs-fixer fix

psalm:
	vendor/bin/psalm --no-cache --show-info=true

php-test:
	vendor/bin/phpunit

.PHONY: php-lint psalm php-test

# 3. Vue
js-init:
	npm ci
	bash scripts/patch-external.sh

js-lint:
	npx vue-tsc --noEmit --skipLibCheck

PRETTIER_PATHS = src e2e scripts/*.ts webpack*.ts playwright.config.ts

format:
	npx prettier --write $(PRETTIER_PATHS)

format-check:
	npx prettier --check $(PRETTIER_PATHS)

build-js:
	npm run dev

build-js-production:
	npm run build

watch-js:
	npm run watch

.PHONY: js-lint format format-check build-js build-js-production watch-js

# 4. Lint
lint: php-lint psalm js-lint format-check

.PHONY: lint

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
