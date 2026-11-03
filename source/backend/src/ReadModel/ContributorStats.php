<?php

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Contributors of one repository and their commits. Searching, the date range, sorting
 * and paging all happen in SQL, so the cost does not grow with what PHP would hold.
 */
final class ContributorStats
{
    /** Best label for the interface: the login if known, otherwise the git name, otherwise the email. */
    private const DISPLAY_NAME = 'COALESCE(a.login, a.name, a.email, a.identity_key)';

    /** API sort name => SQL expression. Only these ever reach ORDER BY. */
    public const SORTS = [
        'commits' => 'commit_count',
        // PostgreSQL accepts an output alias in ORDER BY only on its own, not inside lower().
        'name' => 'lower('.self::DISPLAY_NAME.')',
        'lastCommit' => 'last_commit_at',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $from inclusive
     * @param \DateTimeImmutable|null $to   exclusive
     * @param key-of<self::SORTS>     $sort
     * @param 'asc'|'desc'            $dir
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function contributors(
        int $repositoryId,
        ?string $search,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $sort,
        string $dir,
        int $page,
        int $perPage,
    ): array {
        $where = ['c.repository_id = :repository'];
        $params = ['repository' => $repositoryId];

        if ($from !== null) {
            $where[] = 'c.authored_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:sP');
        }
        if ($to !== null) {
            $where[] = 'c.authored_at < :to';
            $params['to'] = $to->format('Y-m-d H:i:sP');
        }
        if ($search !== null) {
            $where[] = '(a.login ILIKE :search OR a.name ILIKE :search OR a.email ILIKE :search)';
            $params['search'] = '%'.addcslashes($search, '\\%_').'%';
        }

        $source = 'FROM commits c JOIN authors a ON a.id = c.author_id WHERE '.implode(' AND ', $where);
        $direction = $dir === 'asc' ? 'ASC' : 'DESC';

        // a.id breaks ties, so a row never appears on two pages.
        $items = $this->connection->fetchAllAssociative(
            'SELECT a.id AS author_id, '.self::DISPLAY_NAME.' AS display_name,
                    a.login, a.email, count(*) AS commit_count, max(c.authored_at) AS last_commit_at
             '.$source.'
             GROUP BY a.id
             ORDER BY '.self::SORTS[$sort].' '.$direction.', a.id ASC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        $total = (int) $this->connection->fetchOne('SELECT count(DISTINCT c.author_id) '.$source, $params);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{id: int, display_name: string, login: ?string, email: ?string}|null
     */
    public function author(int $authorId): ?array
    {
        /** @var array{id: int, display_name: string, login: ?string, email: ?string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT a.id, '.self::DISPLAY_NAME.' AS display_name, a.login, a.email FROM authors a WHERE a.id = ?',
            [$authorId],
        );

        return $row === false ? null : $row;
    }

    /**
     * One author's commits in one repository, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function commits(int $repositoryId, int $authorId, int $page, int $perPage): array
    {
        $items = $this->connection->fetchAllAssociative(
            'SELECT sha, authored_at, author_name, author_email, html_url
             FROM commits WHERE repository_id = ? AND author_id = ?
             ORDER BY authored_at DESC, id DESC
             LIMIT ? OFFSET ?',
            [$repositoryId, $authorId, $perPage, ($page - 1) * $perPage],
        );

        $total = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commits WHERE repository_id = ? AND author_id = ?',
            [$repositoryId, $authorId],
        );

        return ['items' => $items, 'total' => $total];
    }
}
