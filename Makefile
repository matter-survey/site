# Matter Survey Site - Makefile

-include .env
-include .env.local
export

.PHONY: help install dev prod clean lint test db-init db-reset db-migrate db-fixtures test-reset deploy

# Default target
help:
	@echo "Matter Survey Site"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "Development:"
	@echo "  install     Install PHP dependencies"
	@echo "  dev         Start development server (localhost:8000)"
	@echo "  lint        Run code style checks"
	@echo "  test        Run tests"
	@echo ""
	@echo "Database:"
	@echo "  db-migrate  Run database migrations"
	@echo "  db-init     Initialize database (run migrations)"
	@echo "  db-reset    Reset database (WARNING: deletes all data)"
	@echo "  db-fixtures Load fixtures into dev database"
	@echo "  db-backup   Backup database to timestamped file"
	@echo "  db-status   Show migration status"
	@echo ""
	@echo "Testing:"
	@echo "  test        Run tests"
	@echo "  test-reset  Reset test database and reload fixtures"
	@echo ""
	@echo "Production:"
	@echo "  prod        Install production dependencies"
	@echo "  clear-cache Clear Symfony cache"
	@echo "  deploy      Deploy to production server via rsync"
	@echo ""
	@echo "Docker:"
	@echo "  docker-up   Start with Docker Compose"
	@echo "  docker-down Stop Docker containers"
	@echo "  docker-logs View Docker logs"

# Development
install:
	composer install

dev:
	php -S localhost:8000 -t public public/router.php

prod:
	composer install --no-dev --optimize-autoloader
	php bin/console cache:clear --env=prod

clear-cache:
	rm -rf var/cache/*

# Linting
lint:
	vendor/bin/php-cs-fixer fix --dry-run --diff || true
	vendor/bin/phpstan analyse src --level=5 || true

lint-fix:
	vendor/bin/php-cs-fixer fix

# Database
db-migrate:
	@mkdir -p data
	php bin/console doctrine:migrations:migrate --no-interaction

db-init: db-migrate
	@echo "Database initialized via migrations"

db-reset:
	@echo "WARNING: This will delete all data!"
	@read -p "Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ]
	rm -f data/matter-survey.db
	$(MAKE) db-migrate

db-backup:
	@mkdir -p backups
	cp data/matter-survey.db backups/matter-survey-$$(date +%Y%m%d-%H%M%S).db
	@echo "Backup created in backups/"

db-stats:
	@sqlite3 data/matter-survey.db "SELECT 'Devices:', COUNT(*) FROM devices; \
		SELECT 'Installations:', COUNT(*) FROM installations; \
		SELECT 'Submissions:', COUNT(*) FROM submissions;"

db-status:
	php bin/console doctrine:migrations:status

db-fixtures:
	php bin/console doctrine:fixtures:load --no-interaction

# Testing
test-reset:
	rm -f data/matter-survey-test.db
	php bin/console doctrine:migrations:migrate --no-interaction --env=test
	php bin/console doctrine:fixtures:load --no-interaction --env=test

# Docker
docker-up:
	docker compose up -d

docker-down:
	docker compose down

docker-logs:
	docker compose logs -f

docker-build:
	docker compose build --no-cache

test:
	vendor/bin/phpunit

# Quick check endpoint
check:
	@curl -s http://localhost:8000/api/submit -X POST \
		-H "Content-Type: application/json" \
		-d '{"installation_id":"00000000-0000-0000-0000-000000000000","devices":[]}' \
		| jq . || echo "Server not running or jq not installed"

# Deployment
deploy:
	@test -n "$(SFTP_USER)" || (echo "SFTP_USER not set in .env" && exit 1)
	@test -n "$(SFTP_HOST)" || (echo "SFTP_HOST not set in .env" && exit 1)
	@test -n "$(SFTP_PATH)" || (echo "SFTP_PATH not set in .env" && exit 1)
	@echo "Deploying to $(SFTP_USER)@$(SFTP_HOST):$(SFTP_PATH)"
	rsync -avz --delete \
		--exclude='.git' \
		--exclude='.env' \
		--exclude='.env.local' \
		--exclude='data/' \
		--exclude='var/' \
		--exclude='/vendor/' \
		--exclude='.claude/' \
		./ $(SFTP_USER)@$(SFTP_HOST):$(SFTP_PATH)/
	ssh $(SFTP_USER)@$(SFTP_HOST) "cd $(SFTP_PATH) && composer install --no-dev --optimize-autoloader && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console cache:clear --env=prod"
	@echo "Deployment complete"
