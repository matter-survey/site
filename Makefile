# Matter Survey Site - Makefile

-include .env
export

.PHONY: help install dev prod clean lint test db-init db-reset deploy

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
	@echo "  db-init     Initialize database with schema"
	@echo "  db-reset    Reset database (WARNING: deletes all data)"
	@echo "  db-backup   Backup database to timestamped file"
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
db-init:
	@mkdir -p data
	sqlite3 data/matter-survey.db < schema.sql
	@echo "Database initialized at data/matter-survey.db"

db-reset:
	@echo "WARNING: This will delete all data!"
	@read -p "Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ]
	rm -f data/matter-survey.db
	$(MAKE) db-init

db-backup:
	@mkdir -p backups
	cp data/matter-survey.db backups/matter-survey-$$(date +%Y%m%d-%H%M%S).db
	@echo "Backup created in backups/"

db-stats:
	@sqlite3 data/matter-survey.db "SELECT 'Devices:', COUNT(*) FROM devices; \
		SELECT 'Installations:', COUNT(*) FROM installations; \
		SELECT 'Submissions:', COUNT(*) FROM submissions;"

# Docker
docker-up:
	docker compose up -d

docker-down:
	docker compose down

docker-logs:
	docker compose logs -f

docker-build:
	docker compose build --no-cache

# Testing
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
		--exclude='data/' \
		--exclude='var/' \
		--exclude='vendor/' \
		--exclude='.claude/' \
		./ $(SFTP_USER)@$(SFTP_HOST):$(SFTP_PATH)/
	ssh $(SFTP_USER)@$(SFTP_HOST) "cd $(SFTP_PATH) && composer install --no-dev --optimize-autoloader && php bin/console cache:clear --env=prod"
	@echo "Deployment complete"
