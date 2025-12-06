# Suggested Commands

## Development
```bash
# Start local dev server
make dev
# or
php -S localhost:8000 -t public public/router.php

# Install dependencies
composer install
```

## Quality Assurance (run before commits)
```bash
# Check code style
make lint

# Fix code style
make lint-fix

# Run PHPStan analysis (level 6)
make analyse

# Run tests
php bin/phpunit
APP_ENV=test php bin/phpunit  # Explicit test environment
php bin/phpunit --filter ClassName  # Run specific test class
```

## Database
```bash
# Run migrations
php bin/console doctrine:migrations:migrate

# Load fixtures
php bin/console doctrine:fixtures:load --group=matter --append

# Clear cache
php bin/console cache:clear
```

## Console Commands
```bash
# Sync DCL vendor/product data
php bin/console app:dcl:sync

# Sync cluster details from Matter SDK
php bin/console app:zap:sync

# Rebuild device scores cache
php bin/console app:scores:rebuild
```

## Deployment
```bash
make deploy  # Requires SFTP credentials in .env.local
```
