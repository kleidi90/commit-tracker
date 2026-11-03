<?php

declare(strict_types=1);

namespace App\Import;

use App\Provider\CommitDto;
use Doctrine\DBAL\Connection;

/**
 * Turns the commits of one page into author ids, creating authors that are new.
 *
 * One INSERT ... ON CONFLICT (provider, identity_key) DO UPDATE ... RETURNING per page:
 * no check-then-insert, so two workers importing repositories that share an author
 * cannot both create it.
 */
final class AuthorIdentityResolver
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<CommitDto> $commits
     *
     * @return list<int> the author id of each commit, in the same order
     */
    public function resolve(string $provider, array $commits): array
    {
        if ($commits === []) {
            return [];
        }

        $keys = [];
        /** @var array<string, CommitDto> $firstByKey */
        $firstByKey = [];
        foreach ($commits as $commit) {
            $key = AuthorIdentity::keyFor($provider, $commit);
            $keys[] = $key;
            // Commits arrive newest first, so the first one carries the most recent name.
            // One row per key: PostgreSQL refuses to update the same row twice in one statement.
            $firstByKey[$key] ??= $commit;
        }

        // A fixed order means concurrent imports lock author rows in the same order and cannot deadlock.
        ksort($firstByKey, SORT_STRING);

        $values = [];
        $params = [];
        foreach ($firstByKey as $key => $commit) {
            $values[] = '(?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $provider,
                $key,
                $key === AuthorIdentity::UNKNOWN ? null : $commit->authorExternalId,
                self::nullIfBlank($commit->authorLogin, 100),
                self::nullIfBlank($commit->authorName, 255),
                self::nullIfBlank($commit->authorEmail, 320),
            );
        }

        // The login follows the account (it can be renamed); name and email keep the first value
        // seen, because older pages arrive later and must not overwrite newer display data.
        // DO UPDATE rather than DO NOTHING so RETURNING also yields rows that already existed.
        $rows = $this->connection->fetchAllAssociative(
            'INSERT INTO authors (provider, identity_key, external_id, login, name, email) VALUES '.implode(', ', $values).'
             ON CONFLICT (provider, identity_key) DO UPDATE SET
                 login = COALESCE(EXCLUDED.login, authors.login),
                 name = COALESCE(authors.name, EXCLUDED.name),
                 email = COALESCE(authors.email, EXCLUDED.email)
             RETURNING id, identity_key',
            $params,
        );

        $idByKey = array_column($rows, 'id', 'identity_key');

        return array_map(static fn (string $key): int => (int) $idByKey[$key], $keys);
    }

    private static function nullIfBlank(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
