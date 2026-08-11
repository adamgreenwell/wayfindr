SERVER_DIR := apps/server

.PHONY: help php-version-test public-artifact-install-test public-info-check public-info-test self-host-test services-up services-down server-install server-migrate server-serve server-test wiki-sync-dry-run wiki-test

help:
	@printf '%s\n' 'Wayfindr development commands:'
	@printf '%s\n' '  make php-version-test   Check that every PHP minimum agrees'
	@printf '%s\n' '  make public-artifact-install-test'
	@printf '%s\n' '                          Test public release artifacts in Docker (destructive to its evidence project)'
	@printf '%s\n' '  make public-info-check  Check tracked files for sensitive markers'
	@printf '%s\n' '  make public-info-test   Test the public-info boundary guard'
	@printf '%s\n' '  make self-host-test     Test the installer and compose stack (needs Docker)'
	@printf '%s\n' '  make wiki-test          Validate Wiki navigation and authority links'
	@printf '%s\n' '  make wiki-sync-dry-run  Preview docs/wiki against the GitHub Wiki'
	@printf '%s\n' '  make services-up      Start Postgres and Redis'
	@printf '%s\n' '  make services-down    Stop local services'
	@printf '%s\n' '  make server-install   Install Laravel dependencies and create .env'
	@printf '%s\n' '  make server-migrate   Run Laravel migrations'
	@printf '%s\n' '  make server-test      Run the Laravel Pest suite'
	@printf '%s\n' '  make server-serve     Serve Laravel on http://localhost:8000'

public-info-check:
	scripts/check-public-info-boundary.sh

public-info-test:
	scripts/test-public-info-boundary.sh

php-version-test:
	scripts/test-php-version-contract.sh

wiki-test:
	scripts/test-wiki-docs.sh

wiki-sync-dry-run: wiki-test
	scripts/sync-github-wiki.sh --dry-run

# The installer is shipped code that operators curl into bash, and two of these
# guard rules the artifact ALSO implements — see docs/development/testing.md.
self-host-test: php-version-test
	scripts/test-self-host-env-generator.sh
	scripts/test-self-host-compose-template.sh
	scripts/test-self-host-env-value.sh
	scripts/test-self-host-classification.sh

public-artifact-install-test:
	scripts/smoke/public-artifact-install.sh

services-up:
	docker compose up -d postgres redis

services-down:
	docker compose down

server-install:
	cd $(SERVER_DIR) && composer install
	test -f $(SERVER_DIR)/.env || cp $(SERVER_DIR)/.env.example $(SERVER_DIR)/.env
	cd $(SERVER_DIR) && php artisan key:generate --ansi

server-migrate:
	cd $(SERVER_DIR) && php artisan migrate

server-serve:
	cd $(SERVER_DIR) && php artisan serve --host=127.0.0.1 --port=8000

server-test:
	cd $(SERVER_DIR) && composer test
