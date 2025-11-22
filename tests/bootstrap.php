<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Initialize test database once before all tests
// DAMA DoctrineTestBundle will wrap each test in a transaction
$projectDir = dirname(__DIR__);
$testDbPath = $projectDir . '/data/matter-survey-test.db';

// Only initialize if database doesn't exist or is empty
$shouldInitialize = !file_exists($testDbPath) || filesize($testDbPath) === 0;

if ($shouldInitialize) {
    // Ensure data directory exists
    if (!is_dir($projectDir . '/data')) {
        mkdir($projectDir . '/data', 0755, true);
    }

    // Run migrations
    passthru('php ' . escapeshellarg($projectDir . '/bin/console') . ' doctrine:migrations:migrate --no-interaction --env=test 2>&1');

    // Load fixtures
    passthru('php ' . escapeshellarg($projectDir . '/bin/console') . ' doctrine:fixtures:load --no-interaction --env=test 2>&1');
}
