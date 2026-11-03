<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Inserts rows directly, so read tests control every date and do not depend on the import.
 */
trait SeedsCommits
{
    protected static function seedRepository(Connection $connection, string $fullName): int
    {
        [$owner, $name] = explode('/', $fullName);

        return (int) $connection->fetchOne(
            "INSERT INTO repositories (provider, owner, name, lookup_key, created_at) VALUES ('github', ?, ?, ?, now()) RETURNING id",
            [$owner, $name, strtolower($fullName)],
        );
    }

    protected static function seedAuthor(Connection $connection, string $key, ?string $login, ?string $name, ?string $email): int
    {
        return (int) $connection->fetchOne(
            "INSERT INTO authors (provider, identity_key, login, name, email) VALUES ('github', ?, ?, ?, ?) RETURNING id",
            [$key, $login, $name, $email],
        );
    }

    /**
     * @param list<string> $dates one commit per date
     */
    protected static function seedCommits(Connection $connection, int $repositoryId, int $authorId, array $dates): void
    {
        foreach ($dates as $date) {
            $sha = sha1($repositoryId.'-'.$authorId.'-'.$date);
            $connection->executeStatement(
                "INSERT INTO commits (repository_id, author_id, sha, authored_at, author_name, author_email, html_url) VALUES (?, ?, ?, ?, 'git name', 'git@example.com', ?)",
                [$repositoryId, $authorId, $sha, $date, 'https://github.com/acme/widgets/commit/'.$sha],
            );
        }
    }
}
