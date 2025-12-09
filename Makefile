DOCKER:= docker
DOCKER_COMPOSE:= docker compose
NPX:= npx
CNTR_NAME_HTTPD := epi-citations-httpd
CNTR_NAME_PHP := epi-citations-php-fpm
CNTR_APP_DIR := /var/www/htdocs
CNTR_APP_USER := www-data

MYSQL_CONNECT_CITATIONS:= mysql -u root -proot -h 127.0.0.1 -P 33063 citations

.PHONY: build up down clean help generate-ssl-certs test test-php test-js test-unit test-integration test-functional lint lint-php lint-js coverage install-deps npm-install npm-build db-test-create db-test-migrate db-test-fixtures db-test-reset ci

help: ## Display this help
	@echo "Available targets:"
	@grep -E '^[a-zA-Z_-]+:.*##' Makefile | awk 'BEGIN {FS = ":.*?## "}; {printf "%-30s %s\n", $$1, $$2}'

build: ## Build the docker containers
	$(DOCKER_COMPOSE) build

up: ## Start all the docker containers
	$(DOCKER_COMPOSE) up -d
	@echo "====================================================================="
	@echo "Make sure you have [127.0.0.1 localhost citations-dev.episciences.org] in /etc/hosts"
	@echo "Citation Manager (HTTPS) : https://citations-dev.episciences.org/"
	@echo "Citation Manager (HTTP)  : http://citations-dev.episciences.org:8081/ (redirects to HTTPS)"
	@echo "PhpMyAdmin               : http://localhost:8002/"
	@echo "====================================================================="
	@echo "Note: HTTPS uses self-signed certificate. Browser will show security warning."
	@echo "      To regenerate SSL certificates, run: make generate-ssl-certs"
	@echo "====================================================================="
	@echo "SQL Place Custom SQL dump files in ~/tmp/"
	@echo "SQL: Import '~/tmp/citations.sql' with 'make load-db-citations'"

down: ## Stop the docker containers and remove orphans
	$(DOCKER_COMPOSE) down --remove-orphans

clean: down ## Clean up unused docker resources
	#docker stop $(docker ps -a -q)
	docker system prune -f

load-db-citations: ## Load an SQL dump from ~/tmp/citations.sql
	$(MYSQL_CONNECT_CITATIONS) < ~/tmp/episciences.sql

composer-install: ## Install composer dependencies
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) composer install --no-interaction --prefer-dist --optimize-autoloader

composer-update: ## Update composer dependencies
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) composer update --no-interaction --prefer-dist --optimize-autoloader

yarn-encore-production: ## yarn encore production
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) yarn install; yarn encore production

restart-httpd: ## Restart Apache httpd
	$(DOCKER_COMPOSE) restart $(CNTR_NAME_HTTPD)

restart-php: ## Restart PHP-FPM Container
	$(DOCKER_COMPOSE) restart $(CNTR_NAME_PHP)

can-i-use-update: ## To be launched when Browserslist: caniuse-lite is outdated.
	$(NPX) update-browserslist-db@latest

enter-container-php: ## Open shell on PHP container
	$(DOCKER) exec -it $(CNTR_NAME_PHP) sh -c "cd /var/www/htdocs && /bin/bash"

enter-container-httpd: ## Open shell on HTTPD container
	$(DOCKER) exec -it $(CNTR_NAME_HTTPD) sh -c "cd /var/www/htdocs && /bin/bash"

cache-clear-prod: ## Clear Symfony production cache
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) php bin/console cache:clear --env=prod

cache-clear-dev: ## Clear Symfony development cache
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) php bin/console cache:clear --env=dev

dump-env-prod: ## Generate .env.local.php for production
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) composer dump-env prod

dump-env-dev: ## Generate .env.local.php for development
	$(DOCKER_COMPOSE) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) composer dump-env dev

fix-cache-permissions: ## Fix cache directory permissions
	$(DOCKER_COMPOSE) exec $(CNTR_NAME_PHP) chown -R $(CNTR_APP_USER):$(CNTR_APP_USER) $(CNTR_APP_DIR)/var/cache
	$(DOCKER_COMPOSE) exec $(CNTR_NAME_PHP) chmod -R 777 $(CNTR_APP_DIR)/var/cache

generate-ssl-certs: ## Generate self-signed SSL certificates for HTTPS
	@echo "Generating SSL certificates..."
	./docker/apache/generate-ssl-certs.sh
	@echo "SSL certificates generated. Restart containers with 'make restart-httpd' to apply."

# ============================================================================
# TESTING COMMANDS
# ============================================================================

# Colors for testing commands
BLUE := \033[0;34m
GREEN := \033[0;32m
YELLOW := \033[0;33m
NC := \033[0m # No Color

install-deps: composer-install npm-install ## Install all dependencies (PHP + JS)
	@echo "$(GREEN)✓ All dependencies installed$(NC)"

npm-install: ## Install npm dependencies
	@echo "$(YELLOW)Installing npm dependencies...$(NC)"
	npm install
	@echo "$(GREEN)✓ npm dependencies installed$(NC)"

npm-build: ## Build production assets
	@echo "$(YELLOW)Building production assets...$(NC)"
	npm run build
	@echo "$(GREEN)✓ Assets built$(NC)"

# Testing - All
test: ## Run all tests (PHP + JavaScript)
	@echo "$(BLUE)Running all tests...$(NC)"
	@make test-php
	@make test-js
	@echo "$(GREEN)✓ All tests passed$(NC)"

# Testing - PHP
test-php: ## Run all PHP tests
	@echo "$(YELLOW)Running PHP tests...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpunit
	@echo "$(GREEN)✓ PHP tests completed$(NC)"

test-unit: ## Run PHP unit tests only
	@echo "$(YELLOW)Running PHP unit tests...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpunit tests/Unit
	@echo "$(GREEN)✓ Unit tests completed$(NC)"

test-integration: ## Run PHP integration tests only
	@echo "$(YELLOW)Running PHP integration tests...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpunit tests/Integration
	@echo "$(GREEN)✓ Integration tests completed$(NC)"

test-functional: ## Run PHP functional tests only
	@echo "$(YELLOW)Running PHP functional tests...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpunit tests/Functional
	@echo "$(GREEN)✓ Functional tests completed$(NC)"

# Testing - JavaScript
test-js: ## Run JavaScript tests
	@echo "$(YELLOW)Running JavaScript tests...$(NC)"
	npm test
	@echo "$(GREEN)✓ JavaScript tests completed$(NC)"

test-js-watch: ## Run JavaScript tests in watch mode
	@echo "$(YELLOW)Running JavaScript tests in watch mode...$(NC)"
	npm test -- --watch

# Code Coverage
coverage: ## Generate code coverage reports (PHP + JS)
	@echo "$(YELLOW)Generating PHP coverage...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpunit --coverage-html coverage/php
	@echo "$(YELLOW)Generating JavaScript coverage...$(NC)"
	npm test -- --coverage
	@echo "$(GREEN)✓ Coverage reports generated$(NC)"
	@echo "$(BLUE)PHP coverage: coverage/php/index.html$(NC)"
	@echo "$(BLUE)JS coverage: coverage/lcov-report/index.html$(NC)"

coverage-php: ## Generate PHP coverage report only
	@echo "$(YELLOW)Generating PHP coverage...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpunit --coverage-html coverage/php
	@echo "$(GREEN)✓ PHP coverage: coverage/php/index.html$(NC)"

coverage-js: ## Generate JavaScript coverage report only
	@echo "$(YELLOW)Generating JavaScript coverage...$(NC)"
	npm test -- --coverage
	@echo "$(GREEN)✓ JS coverage: coverage/lcov-report/index.html$(NC)"

# Linting
lint: ## Run all linters (PHP + JS)
	@echo "$(BLUE)Running all linters...$(NC)"
	@make lint-php
	@make lint-js
	@echo "$(GREEN)✓ All linting passed$(NC)"

lint-php: ## Run PHPStan analysis
	@echo "$(YELLOW)Running PHPStan...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) vendor/bin/phpstan analyse
	@echo "$(GREEN)✓ PHPStan analysis completed$(NC)"

lint-js: ## Run ESLint
	@echo "$(YELLOW)Running ESLint...$(NC)"
	$(NPX) eslint assets/**/*.js
	@echo "$(GREEN)✓ ESLint completed$(NC)"

lint-js-fix: ## Run ESLint with auto-fix
	@echo "$(YELLOW)Running ESLint with auto-fix...$(NC)"
	$(NPX) eslint assets/**/*.js --fix
	@echo "$(GREEN)✓ ESLint auto-fix completed$(NC)"

# Test Database
db-test-create: ## Create test database
	@echo "$(YELLOW)Creating test database...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) php bin/console doctrine:database:create --env=test --if-not-exists
	@echo "$(GREEN)✓ Test database created$(NC)"

db-test-migrate: ## Run database migrations (test env)
	@echo "$(YELLOW)Running migrations...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) php bin/console doctrine:migrations:migrate --env=test --no-interaction
	@echo "$(GREEN)✓ Migrations completed$(NC)"

db-test-fixtures: ## Load fixtures (test env)
	@echo "$(YELLOW)Loading fixtures...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) php bin/console doctrine:fixtures:load --env=test --no-interaction
	@echo "$(GREEN)✓ Fixtures loaded$(NC)"

db-test-reset: ## Reset test database (drop, create, migrate, fixtures)
	@echo "$(YELLOW)Resetting test database...$(NC)"
	$(DOCKER) exec -w $(CNTR_APP_DIR) $(CNTR_NAME_PHP) php bin/console doctrine:database:drop --env=test --force --if-exists
	@make db-test-create
	@make db-test-migrate
	@make db-test-fixtures
	@echo "$(GREEN)✓ Test database reset completed$(NC)"

# CI simulation
ci: ## Run CI checks locally (tests + lint)
	@echo "$(BLUE)Running CI checks locally...$(NC)"
	@make lint
	@make test
	@echo "$(GREEN)✓ CI checks passed$(NC)"