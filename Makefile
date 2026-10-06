TOOLS := tools/vendor/bin
PHP74 := docker run --rm --user "$$(id -u):$$(id -g)" -v "$(CURDIR):/app" -w /app php:7.4-cli

install: ## Install package and tool dependencies
	composer install
	composer install --working-dir=tools

check: ## Static checks (incl. PHP 7.4 syntax lint)
	$(TOOLS)/phpstan analyse --memory-limit=512M -c phpstan.dist.neon
	$(TOOLS)/php-cs-fixer check
	composer validate --strict --no-check-publish
	$(TOOLS)/rector process -n
	$(TOOLS)/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered
	$(MAKE) lint-74

lint-74: ## Lint sources with the PHP 7.4 parser
	$(PHP74) sh -c "find src tests -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null"

test: ## Run tests on the local PHP
	vendor/bin/phpunit

test-74: ## Run tests on PHP 7.4
	$(PHP74) vendor/bin/phpunit

test-targets: ## Lint and run the golden emitter output on each target PHP version (Docker)
	sh tests/Targets/run.sh

verify: check test test-74 ## Full gate: static checks + tests on local PHP and PHP 7.4

docker-build: ## Build the Docker image as dto-generator:local, with the sibling bridge checkout when there is one
	sh docker/build.sh dto-generator:local

docker-smoke: docker-build ## Generate the golden project with the image and compare
	sh tests/Docker/smoke.sh dto-generator:local

infection: ## Mutation testing
	XDEBUG_MODE=coverage $(TOOLS)/infection --threads=$(shell nproc) --no-interaction

fix: ## Apply code style and Rector
	$(TOOLS)/rector process
	$(TOOLS)/php-cs-fixer fix

help: ## List commands
	@grep -E '^[a-zA-Z_0-9-]+:.*?## ' Makefile | awk 'BEGIN {FS = ":.*?## "}; {printf "%-12s %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
.PHONY: install check lint-74 test test-74 test-targets verify infection fix help
