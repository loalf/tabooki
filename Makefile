# Tabooki: developer commands. Run `make` to list them.
.DEFAULT_GOAL := help
.PHONY: help build up down restart logs ps sh console composer migrate migration user front front-install \
        api-types test phpstan cs cs-fix check prod-up prod-logs

DC      := docker compose
PHP     := $(DC) exec php
CONSOLE := $(PHP) php bin/console
PROD    := docker compose -f compose.yaml -f compose.prod.yaml

help: ## List commands
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## —— Docker (development) ————————————————————————————————————
build: ## Build the dev images
	$(DC) build --pull

up: ## Start php, worker and database (https://localhost)
	$(DC) up --detach --wait

down: ## Stop everything
	$(DC) down --remove-orphans

restart: down up ## Restart everything

logs: ## Follow logs (e.g. make logs s=worker)
	$(DC) logs --tail=100 --follow $(s)

ps: ## Show running services
	$(DC) ps

sh: ## Shell into the php container
	$(PHP) bash

## —— Symfony ——————————————————————————————————————————————————
console: ## Run a console command, e.g. make console c="debug:router"
	$(CONSOLE) $(c)

composer: ## Run composer, e.g. make composer c="require foo/bar"
	$(PHP) composer $(c)

migration: ## Generate a Doctrine migration from entity changes
	$(CONSOLE) doctrine:migrations:diff --no-interaction

migrate: ## Run pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

user: ## Create a user, e.g. make user email=me@example.com name="Me" household="Casa"
	$(DC) exec -it php php bin/console app:user:create "$(email)" --name="$(name)" --household="$(household)"

## —— Frontend —————————————————————————————————————————————————
front-install: ## Install frontend dependencies
	cd frontend && npm install

front: ## Start the Vite dev server (open https://localhost, not :5174)
	cd frontend && npm run dev

api-types: ## Regenerate TypeScript API types from the OpenAPI spec
	$(CONSOLE) api:openapi:export --no-interaction > frontend/openapi.json
	cd frontend && npm run api:types

## —— Quality ——————————————————————————————————————————————————
test: ## Run backend tests
	$(PHP) bash -c 'php bin/console doctrine:database:create --if-not-exists --env=test -q && php bin/console dbal:run-sql --env=test -q "CREATE EXTENSION IF NOT EXISTS vector" && php bin/console doctrine:schema:update --force --complete --env=test -q && php bin/phpunit'

phpstan: ## Static analysis (backend)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Check backend code style
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix backend code style
	$(PHP) vendor/bin/php-cs-fixer fix

check: phpstan cs test ## Run all backend checks, plus frontend typecheck and lint
	cd frontend && npm run typecheck && npm run lint

## —— Production (run on the VPS) ——————————————————————————————
prod-up: ## Build and (re)start production
	$(PROD) up --detach --build --wait

prod-logs: ## Follow production logs
	$(PROD) logs --tail=100 --follow $(s)
