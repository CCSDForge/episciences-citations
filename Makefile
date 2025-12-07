DOCKER:= docker
DOCKER_COMPOSE:= docker compose
NPX:= npx
CNTR_NAME_HTTPD := epi-citations-httpd
CNTR_NAME_PHP := epi-citations-php-fpm
CNTR_APP_DIR := /var/www/htdocs
CNTR_APP_USER := www-data

MYSQL_CONNECT_CITATIONS:= mysql -u root -proot -h 127.0.0.1 -P 33063 citations

.PHONY: build up down clean help generate-ssl-certs

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