<?php

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Repositories as the list screen needs them: commit count and latest sync run included,
 * in one query instead of one per repository.
 *
 * @phpstan-type RepositoryRow array{
 *     id: int, provider: string, owner: string, name: string, last_synced_at: ?string, commit_count: int,
 *     run_id: ?int, run_status: ?string, run_commits_fetched: ?int, run_commits_inserted: ?int, run_error: ?string,
 *     run_rate_limit_reset_at: ?string, run_created_at: ?string, run_started_at: ?string, run_finished_at: ?string
 * }
 */
final class RepositoryOverview
{
    private const SELECT = <<<'SQL'
        SELECT r.id, r.provider, r.owner, r.name, r.last_synced_at,
               (SELECT count(*) FROM commits c WHERE c.repository_id = r.id) AS commit_count,
               s.id AS run_id, s.status AS run_status, s.commits_fetched AS run_commits_fetched,
               s.commits_inserted AS run_commits_inserted, s.error AS run_error,
               s.rate_limit_reset_at AS run_rate_limit_reset_at, s.created_at AS run_created_at,
               s.started_at AS run_started_at, s.finished_at AS run_finished_at
        FROM repositories r
        LEFT JOIN LATERAL (
            SELECT * FROM sync_runs WHERE repository_id = r.id ORDER BY id DESC LIMIT 1
        ) s ON true
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<RepositoryRow>
     */
    public function all(): array
    {
        /** @var list<RepositoryRow> */
        return $this->connection->fetchAllAssociative(self::SELECT.' ORDER BY r.lookup_key');
    }

    /**
     * @return RepositoryRow|null
     */
    public function find(int $id): ?array
    {
        /** @var RepositoryRow|false $row */
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE r.id = ?', [$id]);

        return $row === false ? null : $row;
    }
}
