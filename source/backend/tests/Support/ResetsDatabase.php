<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Empties the app_test tables before each test. TRUNCATE is fast and resets the
 * identity columns, so tests never depend on each other's data.
 */
trait ResetsDatabase
{
    protected static function resetDatabase(Connection $connection): void
    {
        $connection->executeStatement('TRUNCATE commits, authors, sync_runs, repositories RESTART IDENTITY CASCADE');
    }

    protected static function rowCount(Connection $connection, string $table): int
    {
        return (int) $connection->fetchOne('SELECT count(*) FROM '.$table);
    }
}
