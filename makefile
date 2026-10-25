# Makefile for {{COMPONENT}}
# Mirrors the moodle-plugin-ci check suite used in GitHub Actions.
#
# Targets:
#   make all          — fix + full check suite (default)
#   make fix          — auto-fix PHP style + PHPDoc + rebuild AMD
#   make check        — check-only (no auto-fix)
#   make clear        — clear terminal
#
# Individual checks:
#   make lint-php     — PHPCS Moodle coding standard
#   make lint-phpdoc  — Moodle PHPDoc checker
#   make lint-js      — ESLint on AMD source files (skipped when amd/src/ is empty)
#   make lint-mustache — Mustache template syntax
#   make lint-cpd     — PHP Copy/Paste Detector (informational)
#   make lint-md      — PHP Mess Detector (informational)
#
# Auto-fixers:
#   make fix-lint-php — phpcbf PHP code-style auto-fix
#   make fix-phpdoc   — moodlecheck PHPDoc report
#   make amd          — rebuild AMD minified files
#   make build        — build BOTH the React bundle (esbuild) and AMD (grunt)
#   make react        — bundle the React editor (esbuild → js/build/)
#   make lint-react   — TypeScript type-check (tsc --noEmit)
#   make test-react   — Jest unit tests for the React/TS sources
#
# Tests:
#   make phpunit      — PHPUnit testsuite for this plugin
#   make playwright   — browser (Playwright) tests (installs Playwright on 1st run)
#   make jmeter       — JMeter read-endpoint load test (downloads JMeter on 1st run)
#   make load-seed    — seed a load-test course + web-service token; prints exports
#
# Setup only:
#   make playwright-setup — install Playwright + Chromium browser
#   make jmeter-setup     — download Apache JMeter
#
# Paths are auto-detected from the makefile's own location.
# The plugin lives at <MOODLE_ROOT>/{{PLUGINPATH}}/ —
# levels below the Moodle root — so both PLUGIN_DIR and MOODLE_ROOT are
# derived automatically and work on any installation.
# Override on the command line if necessary:
#   make lint-php MOODLE_ROOT=/opt/moodle

THIS_DIR      := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR    ?= $(THIS_DIR)
MOODLE_ROOT   ?= $(abspath $(PLUGIN_DIR)/../..)
PLUGIN_NAME   ?= {{COMPONENT}}
PLUGIN_REL    ?= {{PLUGINPATH}}
PHP           ?= $(shell which php 2>/dev/null || echo /usr/bin/php)
PHPCS         ?= phpcs
PHPCBF        ?= phpcbf
NPX           ?= npx
# Refresh the browserslist database before a Grunt/ESLint run. Off by default:
# it writes to the lockfile and reaches the network, which a plain `make check`
# should not do. caniuse-lite going stale only produces a warning, never a wrong
# build, so this is a maintenance step rather than part of every run.
#   make amd BROWSERSLIST_UPDATE=1
BROWSERSLIST_UPDATE ?= 0
NPM           ?= npm
# Refresh frontend dependencies before running Jest: `npm update` plus
# `npm audit fix --force`. Off by default because --force accepts breaking major
# versions, which changes the bundled output and therefore the committed
# amd/build artefacts; run it deliberately, then rebuild and re-commit them.
#   make test-react NPM_REFRESH=1
NPM_REFRESH   ?= 0

# --- Browser / load-test tooling -------------------------------------------
PLAYWRIGHT_DIR ?= $(PLUGIN_DIR)/tests/playwright
LOAD_DIR       ?= $(PLUGIN_DIR)/tests/load
JMETER_VERSION ?= 5.6.3
JMETER_HOME    ?= $(LOAD_DIR)/apache-jmeter-$(JMETER_VERSION)
JMETER         ?= $(JMETER_HOME)/bin/jmeter

# Base URL read from the site's own config.php ($CFG->wwwroot), via Moodle's
# ABORT_AFTER_CONFIG so only the config is loaded, not the whole bootstrap.
# Lazily evaluated: only the load / playwright targets expand it. Empty if no
# config.php is present (e.g. a bare checkout) — then the :8000 fallback applies.
MOODLE_WWWROOT = $(shell $(PHP) -r "define('CLI_SCRIPT',1); define('ABORT_AFTER_CONFIG',1); @include '$(MOODLE_ROOT)/config.php'; echo isset(\$$CFG->wwwroot) ? \$$CFG->wwwroot : '';" 2>/dev/null)

# Load-test parameters (override on the command line):
#   make jmeter BASE_URL=http://localhost/moodle45 TOKEN=... WORKSPACEID=.. CMID=..
BASE_URL       ?= $(or $(MOODLE_WWWROOT),http://localhost:8000)
TOKEN          ?=
WORKSPACEID    ?=
CMID           ?=
REVISION       ?= 2000
THREADS        ?= 25
RAMPUP         ?= 10
LOOPS          ?= 20
MAXDURATION    ?= 2000
OPLOG          ?= 5000

# Values written by `make load-seed` (BASE_URL/TOKEN/WORKSPACEID/CMID). Auto-read
# ignores the file when it does not exist yet; a command-line override wins.
-include $(LOAD_DIR)/.load-env
# Optional: override the base URL Playwright uses (otherwise seed.php sets it).
FLEXACCESS_BASE_URL ?= $(MOODLE_WWWROOT)

.PHONY: all fix check clear \
        lint-php lint-phpdoc lint-js lint-mustache lint-cpd lint-md \
        lint-react test-react react build \
        fix-lint-php fix-phpdoc amd phpunit \

all: clear fix check
	@echo ""
	@echo "=== All checks complete. Review output above for errors. ==="

fix: clear fix-phpdoc fix-lint-php build
	@echo ""
	@echo "=== All fixes complete. ==="

check: clear lint-php lint-phpdoc lint-mustache lint-cpd lint-js lint-react build test-react phpunit
	@echo ""
	@echo "=== All checks complete. Review output above for errors. ==="

clear:
	clear

lint-php:
	@echo "=== phpcs (Moodle standard, excludes tools/) ==="
	-cd $(PLUGIN_DIR) && $(PHPCS) \
		--standard=moodle \
		--extensions=php \
		--severity=1 \
		--no-cache \
		--ignore=tools/ \
		.

fix-lint-php:
	@echo ""
	@echo "=== phpcbf (auto-fix) ==="
	-cd $(PLUGIN_DIR) && $(PHPCBF) \
		--standard=moodle \
		--extensions=php \
		.

lint-phpdoc:
	@echo ""
	@echo "=== PHPDoc (local_moodlecheck, excludes tools/) ==="
	-cd $(MOODLE_ROOT) && $(PHP) local/moodlecheck/cli/moodlecheck.php \
		--path=$(PLUGIN_REL) \
		--exclude=$(PLUGIN_REL)/tools \
		--format=text 2>&1 | grep -B1 '    Line' | grep -v '^--$$' || true

fix-phpdoc:
	@echo ""
	@echo "=== fix_phpdoc (tools/fix_phpdoc.php) ==="
	-$(PHP) $(PLUGIN_DIR)/tools/fix_phpdoc.php $(PLUGIN_DIR)

lint-mustache:
	@echo ""
	@echo "=== Mustache syntax check ==="
	@if [ -d $(PLUGIN_DIR)/templates ]; then \
		$(PHP) $(PLUGIN_DIR)/tools/mustache_check.php \
			$(PLUGIN_DIR)/templates 2>&1 | grep -v '^OK:' || true; \
	else \
		echo "No templates/ directory — Mustache check skipped."; \
	fi

lint-cpd:
	@echo ""
	@echo "=== PHP Copy/Paste Detector ==="
	-cd $(PLUGIN_DIR) && phpcpd --min-lines 5 --min-tokens 70 . || true

lint-md:
	@echo ""
	@echo "=== PHP Mess Detector ==="
	@echo "Rules: phpmd.xml (Moodle-incompatible rules excluded, each with a reason)."
	@echo "db/upgrade.php is exempt: its shape is prescribed by Moodle."
	-cd $(PLUGIN_DIR) && phpmd . text phpmd.xml \
		--exclude tests,tools,tests/load,db/upgrade.php || true

lint-js:
	@echo ""
	@echo "=== ESLint (skipped when amd/src/ is empty) ==="
	@if ls $(PLUGIN_DIR)/amd/src/*.js 2>/dev/null | grep -q .; then \
		cd $(MOODLE_ROOT) && $(NPX) grunt eslint --root=. \
			--files=$(PLUGIN_REL)/amd/src/ --show-lint-warnings; \
	else \
		echo "No AMD source files — ESLint skipped."; \
	fi

amd:
	@echo ""
	@echo "=== AMD rebuild (skipped when amd/src/ is empty) ==="
	@if [ "$(BROWSERSLIST_UPDATE)" = "1" ]; then \
		echo "Updating the browserslist database (BROWSERSLIST_UPDATE=1)..."; \
		cd $(PLUGIN_DIR) && $(NPX) browserslist@latest --update-db || true; \
	fi
	@if ls $(PLUGIN_DIR)/amd/src/*.js 2>/dev/null | grep -q .; then \
		cd $(PLUGIN_DIR) && $(NPX) grunt amd --force; \
	else \
		echo "No AMD source files — skipped."; \
	fi

react:
	@echo ""
	@echo "=== React bundle (esbuild → js/build/) ==="
	@if [ -f $(PLUGIN_DIR)/build.mjs ]; then \
		if [ ! -d $(PLUGIN_DIR)/node_modules ]; then \
			echo "Installing frontend dev dependencies..."; \
			cd $(PLUGIN_DIR) && $(NPM) install --no-audit --no-fund; \
		fi; \
		cd $(PLUGIN_DIR) && $(NPM) run build; \
	else \
		echo "No build.mjs — React bundle skipped."; \
	fi

build: react amd
	@echo ""
	@echo "=== Front-end build complete (React bundle + AMD). ==="

lint-react:
	@echo ""
	@echo "=== TypeScript type-check (tsc --noEmit) ==="
	@if [ -f $(PLUGIN_DIR)/tsconfig.json ]; then \
		if [ ! -x $(PLUGIN_DIR)/node_modules/.bin/tsc ]; then \
			echo "Installing frontend dev dependencies..."; \
			cd $(PLUGIN_DIR) && $(NPM) install --no-audit --no-fund; \
		fi; \
		cd $(PLUGIN_DIR) && ./node_modules/.bin/tsc --noEmit; \
	else \
		echo "No tsconfig.json — type-check skipped."; \
	fi

test-react:
	@echo ""
	@echo "=== Jest (React/TS unit tests) ==="
	@if [ -d $(PLUGIN_DIR)/js/tests ]; then \
		if [ ! -x $(PLUGIN_DIR)/node_modules/.bin/jest ]; then \
			echo "Installing frontend dev dependencies..."; \
			cd $(PLUGIN_DIR) && $(NPM) install --no-audit --no-fund; \
		fi; \
		if [ "$(NPM_REFRESH)" = "1" ]; then \
			echo "Refreshing frontend dependencies (NPM_REFRESH=1)..."; \
			cd $(PLUGIN_DIR) && $(NPM) update --no-fund || true; \
			cd $(PLUGIN_DIR) && $(NPM) audit fix --force --no-fund || true; \
		fi; \
		cd $(PLUGIN_DIR) && ./node_modules/.bin/jest; \
	else \
		echo "No js/tests — Jest skipped."; \
	fi

phpunit:
	@echo ""
	@echo "=== PHPUnit ==="
	@if ! $(PHP) -r \
		"define('CLI_SCRIPT',1); require '$(MOODLE_ROOT)/config.php'; \
		exit(empty(\$$CFG->phpunit_dataroot) ? 1 : 0);" 2>/dev/null; then \
		echo "SKIP: phpunit_dataroot not configured."; \
		echo "      Add to config.php: \$$CFG->phpunit_dataroot = '...';"; \
	else \
		reinit_check=$$(cd $(MOODLE_ROOT) && $(PHP) vendor/bin/phpunit \
			--testsuite $(PLUGIN_NAME)_testsuite \
			--testdox 2>&1 | head -5); \
		if printf '%s\n' "$$reinit_check" | grep -q "initialised for different version"; then \
			echo "PHPUnit environment outdated — reinitialising..."; \
			cd $(MOODLE_ROOT) && $(PHP) admin/tool/phpunit/cli/init.php; \
		fi; \
		tmpout=$$(mktemp); \
		cd $(MOODLE_ROOT) && $(PHP) vendor/bin/phpunit \
			--testsuite $(PLUGIN_NAME)_testsuite \
			--testdox > "$$tmpout" 2>&1; \
		phpunit_exit=$$?; \
		grep -v "^ ✔\|^ ✓\|^ ↩" "$$tmpout" || true; \
		rm -f "$$tmpout"; \
		exit $$phpunit_exit; \
	fi

# --- Browser collaboration tests (Playwright) ------------------------------
# Need a RUNNING Moodle site with {{COMPONENT}} installed. seed.php creates the
# course-mode fixture and prints the exports (incl. FLEXACCESS_BASE_URL from the
# site's wwwroot), so no manual base URL is needed for a local install.

playwright-setup:
	@echo ""
	@echo "=== Playwright setup (npm install + Chromium) ==="
	cd $(PLAYWRIGHT_DIR) && $(NPM) install --no-audit --no-fund && $(NPM) run install-browsers

playwright: clear
	@echo ""
	@echo "=== Playwright collaboration tests (needs a running Moodle site) ==="
	@if [ ! -d $(PLAYWRIGHT_DIR)/node_modules ]; then \
		echo "First run: installing Playwright + Chromium..."; \
		cd $(PLAYWRIGHT_DIR) && $(NPM) install --no-audit --no-fund && $(NPM) run install-browsers; \
	fi
	cd $(PLAYWRIGHT_DIR) && eval "$$($(PHP) seed.php)" && \
		$(if $(FLEXACCESS_BASE_URL),FLEXACCESS_BASE_URL='$(FLEXACCESS_BASE_URL)' )$(NPM) test

# --- Seed a load-test course + token for the load tests ---------------------------
# Needs a running, installed Moodle. Prints `export BASE_URL/TOKEN/WORKSPACEID/
# CMID` for the load run. Disposable dev/staging sites only.
load-seed: clear
	@echo ""
	@echo "=== Seed load-test course + web-service token (op-log = $(OPLOG)) ==="
	@$(PHP) $(PLUGIN_DIR)/tests/load/seed_large.php $(OPLOG) | tee $(LOAD_DIR)/.load-seed.out
	@sed -n "s/^export \([A-Z_][A-Z_]*\)=.\(.*\)./\1=\2/p" $(LOAD_DIR)/.load-seed.out > $(LOAD_DIR)/.load-env
	@rm -f $(LOAD_DIR)/.load-seed.out
	@echo ""
	@echo "Saved BASE_URL/TOKEN/WORKSPACEID/CMID to $(LOAD_DIR)/.load-env"

# --- Read-endpoint load test (JMeter) --------------------------------------
# Needs a live, seeded site + a REST web-service token. Seed a load-test course and
# mint a token first — see tests/load/README.md.

jmeter-setup:
	@echo ""
	@echo "=== JMeter setup ==="
	@if [ -x $(JMETER) ]; then \
		echo "JMeter $(JMETER_VERSION) already present at $(JMETER_HOME)."; \
	else \
		echo "Downloading Apache JMeter $(JMETER_VERSION)..."; \
		cd $(LOAD_DIR) && \
		curl -fsSL https://archive.apache.org/dist/jmeter/binaries/apache-jmeter-$(JMETER_VERSION).tgz -o jmeter.tgz && \
		tar xzf jmeter.tgz && rm -f jmeter.tgz && \
		echo "Installed to $(JMETER_HOME)."; \
	fi

jmeter: clear jmeter-setup
	@echo ""
	@echo "=== JMeter load test — read endpoints ==="
	@command -v java >/dev/null 2>&1 || { echo "Java (JRE 8+) is required to run JMeter — please install a JRE."; exit 1; }
	@if [ -z "$(TOKEN)" ] || [ -z "$(WORKSPACEID)" ] || [ -z "$(CMID)" ]; then \
		echo "Missing required parameters. Usage:"; \
		echo "  make jmeter BASE_URL=<wwwroot> TOKEN=<token> WORKSPACEID=<id> CMID=<id> \\"; \
		echo "             [REVISION=2000 THREADS=25 RAMPUP=10 LOOPS=20 MAXDURATION=2000]"; \
		echo ""; \
		echo "  Run 'make load-seed' first (it seeds a load-test course + token and stores them),"; \
		echo "  or pass TOKEN/WORKSPACEID/CMID yourself. See tests/load/README.md."; \
		exit 1; \
	fi
	cd $(LOAD_DIR) && $(JMETER) -n -t {{PLUGINNAME}}-read-endpoints.jmx \
		-Jbase_url='$(BASE_URL)' -Jtoken='$(TOKEN)' \
		-Jworkspaceid='$(WORKSPACEID)' -Jcmid='$(CMID)' -Jrevision='$(REVISION)' \
		-Jthreads='$(THREADS)' -Jrampup='$(RAMPUP)' -Jloops='$(LOOPS)' \
		-Jmaxduration='$(MAXDURATION)' \
		-l {{PLUGINNAME}}-load-results.jtl
	@echo ""
	@echo "Results written to $(LOAD_DIR)/{{PLUGINNAME}}-load-results.jtl"

