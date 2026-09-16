# Makefile for Runtime Env Bundle
# All dev targets use the root docker-compose.yml.

COMPOSE_FILE := docker-compose.yml
# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell PATH="/usr/local/bin:/usr/bin:/bin:$$PATH" command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE_BIN := docker-compose
else
COMPOSE_BIN := $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down down-dev build shell install ensure-up test test-unit test-coverage coverage-php-percent cs-check cs-fix qa clean release-check release-check-demos demo-smoke composer-sync rector rector-dry phpstan update validate setup-hooks check-no-cursor-coauthor strip-cursor-coauthor-from-history validate-translations check-twig-extra check-no-raw-html-form assets update-deps

help:
	@echo "Runtime Env Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up            Start Docker container"
	@echo "  down          Stop Docker container"
	@echo "  down-dev      Stop root compose (dev) and remove orphans"
	@echo "  build         Rebuild Docker image (no cache)"
	@echo "  shell         Open shell in container"
	@echo "  install       Install Composer dependencies"
	@echo "  assets        No-op (no frontend in this bundle)"
	@echo "  test          Run PHPUnit tests (starts container if needed)"
	@echo "  test-coverage Run tests with code coverage (starts container if needed)"
	@echo "  coverage-php-percent  Print Lines %% from coverage-php.txt"
	@echo "  cs-check      Check code style"
	@echo "  cs-fix        Fix code style"
	@echo "  rector        Apply Rector refactoring"
	@echo "  rector-dry    Run Rector in dry-run mode"
	@echo "  phpstan       Run PHPStan static analysis"
	@echo "  qa            Run all QA checks (cs-check + test)"
	@echo "  validate-translations  YAML syntax + key parity (en/es/it/fr/pt/de/nl)"
	@echo "  check-twig-extra       REQ-TWIG-004 twig/extra-bundle gate"
	@echo "  check-no-raw-html-form REQ-TWIG-005 no raw <form>/<input>"
	@echo "  release-check Pre-release: Cursor trailer check, QA, demos"
	@echo "  demo-smoke    REQ-TEST-011: boot primary demo + HTTP 200"
	@echo "  composer-sync Validate composer.json and align composer.lock (no install)"
	@echo "  clean         Remove vendor and cache"
	@echo "  update        Update composer.lock (composer update)"
	@echo "  update-deps   Update bundle and demo Composer locks (Docker)"
	@echo "  validate      Run composer validate --strict"
	@echo "  setup-hooks   Install .githooks (REQ-GIT-001)"
	@echo "  check-no-cursor-coauthor  Fail if Cursor co-author trailers in history"
	@echo ""
	@echo "Demos (Symfony 8): make -C demo help"
	@echo ""

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec $(SERVICE_PHP) composer install --no-interaction
	@echo "✅ Container ready!"

down:
	$(COMPOSE) down

down-dev:
	$(COMPOSE) down --remove-orphans

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	@echo "✅ Dependencies installed."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (root $(COMPOSE))..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-unit: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-unit

test-coverage: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

coverage-php-percent:
	@./.scripts/php-coverage-percent.sh coverage-php.txt

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

release-check: check-no-cursor-coauthor check-twig-extra check-no-raw-html-form ensure-up composer-sync cs-fix cs-check rector-dry phpstan validate-translations test-coverage release-check-demos

release-check-demos:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/Makefile — skip release-check-demos"; fi

demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo demo-smoke; else echo "No demo/Makefile — skip demo-smoke"; fi

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle."

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
