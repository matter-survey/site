<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Database initialization is handled by CI workflow or manually before running tests.
// DAMA DoctrineTestBundle wraps each test in a transaction for isolation.
//
// To set up the test database locally:
//   php bin/console doctrine:database:create --env=test
//   php bin/console doctrine:migrations:migrate --no-interaction --env=test
//   php bin/console doctrine:fixtures:load --no-interaction --env=test --group=test
