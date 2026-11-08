# Nativa Core — the kernel, the module catalogue and the theme builds.
#
# This file is GENERATED into the nativa-core repository from nativa-php-core.
# `.nativa-core-export.json` names the commit it came from. A target that cannot run
# here (against a sibling checkout or the source tree's architectural model)
# is not listed, because a red gate on a fresh clone teaches a reader that the
# gates do not hold. The source repository keeps the full Makefile.
#
# Every target below is registered in toolchain.json with the question it
# answers; `make gates` prints the registry.

PHP ?= php
COMPOSER ?= composer
MAGO_ARGS ?=

##@ Help
help: ## Show available targets
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Setup
install: ## Install PHP dev dependencies (the runtime itself has none)
	$(COMPOSER) install

##@ Toolchain
gates: ## Print the toolchain registry: every pin, every gate and the question it answers
	@$(PHP) bin/ops/toolchain-report.php

##@ Quality
lint: ## Lint all PHP files (php -l), non-zero on a real syntax error
	@find src modules public tests bin -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null

test: ## Run PHPUnit (tests/ plus modules/*/tests/)
	$(COMPOSER) test

analyze: ## Run phpstan + rector dry + cs-fixer dry
	$(COMPOSER) phpstan
	$(COMPOSER) rector-dry
	$(COMPOSER) cs-fix-dry

security-audit: ## Gate: no tracked .env, no secret-shaped literals in tracked code
	@bin/build/security-audit.sh

dependency-audit: ## Gate: no high/critical advisories in either lockfile
	composer audit --locked --ignore-severity=low --ignore-severity=medium
	cd Frontend/packages && pnpm audit --audit-level high

##@ Mago
mago-spike: ## Informational: run mago lint/analyze/format/guard over the phpstan scope
	@bin/build/mago-spike.sh $(MAGO_ARGS)

mago-modules: ## Informational: measure the module catalogue alone (lint + analyze, never a gate)
	@bin/build/mago-spike.sh --scope=modules

mago-guard: ## Gate: the kernel to module dependency rule
	@bin/build/mago-spike.sh --only=guard --strict

mago-lint: ## Gate: curated lint findings must be zero
	@bin/build/mago-spike.sh --only=lint --max-issues=0

##@ Catalogue
modules-install: ## Install every catalogued module (closure order; refuses a cycle)
	bin/build/install-catalogue.sh

admin-smoke: ## Boot the panel with temp DBs and walk the admin flows over HTTP
	bin/build/admin-smoke.sh

##@ Distribution
dist-check: ## Gate: build dist from a clean clone (no vendor/) and smoke it
	@bin/build/dist-check.sh

dist-profile-test: ## Gate: the three deployment-profile branches (skip / skip / fail)
	@bin/build/dist-profile-test.sh

##@ Assets
assets: ## Build the theme bundles in-tree (pnpm workspace under Frontend/packages)
	@command -v pnpm >/dev/null 2>&1 || { \
		echo "pnpm not found. Install it, or use the committed frozen bundles." >&2; \
		exit 1; \
	}
	cd Frontend/packages && pnpm install --frozen-lockfile && pnpm build
	@bin/build/check-theme-bundles.sh
	@echo "Built public/dist/<theme> from Frontend/packages (gitignored; the frozen griffe and mono bundles stay committed)."

##@ Development
dev: ## Start the local dev server (localhost:8000)
	$(PHP) -S 127.0.0.1:8000 -t public/
