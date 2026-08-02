<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the SQLite connection tuning that keeps the mixed read/write workload
 * (page reads vs. /api/submit writes) from serialising on a single writer.
 */
final class SqlitePragmaConfigurationTest extends KernelTestCase
{
    public function testConnectionEnablesWalJournalMode(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $journalMode = (string) $connection->executeQuery('PRAGMA journal_mode')->fetchOne();

        $this->assertSame('wal', strtolower($journalMode));
    }

    public function testConnectionSetsBusyTimeout(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $busyTimeout = (int) $connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

        $this->assertSame(5000, $busyTimeout);
    }
}
