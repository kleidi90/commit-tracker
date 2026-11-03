<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Provider\CommitDto;
use App\Provider\CommitProviderInterface;
use App\Provider\RepoRef;

/**
 * Serves fixed pages, then optionally throws, the way a real provider fails partway.
 */
final class FakeCommitProvider implements CommitProviderInterface
{
    /**
     * @param list<list<CommitDto>> $pages
     */
    public function __construct(
        private array $pages = [],
        private ?\Throwable $failAfterPages = null,
    ) {
    }

    /**
     * @param list<list<CommitDto>> $pages
     */
    public function willServe(array $pages, ?\Throwable $thenFail = null): void
    {
        $this->pages = $pages;
        $this->failAfterPages = $thenFail;
    }

    public function getName(): string
    {
        return 'github';
    }

    public function fetchCommitPages(RepoRef $repo, int $limit): iterable
    {
        yield from $this->pages;

        if ($this->failAfterPages !== null) {
            throw $this->failAfterPages;
        }
    }

    public static function commit(string $sha, string $name = 'Ada', string $email = 'ada@example.com', ?string $accountId = null, ?string $login = null): CommitDto
    {
        return new CommitDto(
            sha: str_pad($sha, 40, '0'),
            authoredAt: new \DateTimeImmutable('2026-01-01T12:00:00Z'),
            authorName: $name,
            authorEmail: $email,
            htmlUrl: 'https://github.com/acme/widgets/commit/'.$sha,
            authorExternalId: $accountId,
            authorLogin: $login,
        );
    }

    /**
     * @return list<list<CommitDto>> $count pages of $perPage commits with distinct hashes
     */
    public static function pages(int $count, int $perPage = 3, string $prefix = 'p'): array
    {
        $pages = [];
        for ($p = 1; $p <= $count; ++$p) {
            $page = [];
            for ($i = 1; $i <= $perPage; ++$i) {
                $page[] = self::commit(sprintf('%s%02d%02d', $prefix, $p, $i), 'Author '.$i, sprintf('author%d@example.com', $i));
            }
            $pages[] = $page;
        }

        return $pages;
    }
}
