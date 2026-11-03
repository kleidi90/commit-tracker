<?php

declare(strict_types=1);

namespace App\Tests\Integration\ReadModel;

use App\ReadModel\ContributorStats;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SeedsCommits;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContributorStatsTest extends KernelTestCase
{
    use ResetsDatabase;
    use SeedsCommits;

    private ContributorStats $stats;
    private int $repositoryId;
    private int $adaId;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::resetDatabase($connection);
        $this->stats = self::getContainer()->get(ContributorStats::class);

        $this->repositoryId = self::seedRepository($connection, 'acme/widgets');
        $other = self::seedRepository($connection, 'acme/other');

        $this->adaId = self::seedAuthor($connection, 'github:1', 'ada', 'Ada Lovelace', 'ada@example.com');
        $bob = self::seedAuthor($connection, 'email:bob@example.com', null, 'Bob', 'bob@example.com');
        $carol = self::seedAuthor($connection, 'github:3', 'carol_c', 'Carol', 'carol@example.com');

        self::seedCommits($connection, $this->repositoryId, $this->adaId, ['2026-01-10 10:00Z', '2026-02-10 10:00Z', '2026-03-10 10:00Z']);
        self::seedCommits($connection, $this->repositoryId, $bob, ['2026-02-15 10:00Z']);
        self::seedCommits($connection, $this->repositoryId, $carol, ['2026-01-20 10:00Z', '2026-01-21 10:00Z']);
        // Another repository: must never be counted.
        self::seedCommits($connection, $other, $this->adaId, ['2026-01-01 10:00Z', '2026-01-02 10:00Z', '2026-01-03 10:00Z', '2026-01-04 10:00Z']);
    }

    public function testCountsCommitsPerAuthorInThisRepositoryOnly(): void
    {
        $result = $this->contributors();

        self::assertSame(3, $result['total']);
        self::assertSame(['ada' => 3, 'carol_c' => 2, 'Bob' => 1], self::countsByName($result['items']));
    }

    public function testSortsByNameCaseInsensitivelyAndByLastCommit(): void
    {
        self::assertSame(['ada', 'Bob', 'carol_c'], self::names($this->contributors(sort: 'name', dir: 'asc')['items']));
        self::assertSame(['ada', 'Bob', 'carol_c'], self::names($this->contributors(sort: 'lastCommit', dir: 'desc')['items']));
        self::assertSame(['Bob', 'carol_c', 'ada'], self::names($this->contributors(sort: 'commits', dir: 'asc')['items']));
    }

    public function testDateRangeLimitsWhichCommitsCount(): void
    {
        $result = $this->contributors(from: '2026-02-01', to: '2026-03-01');

        self::assertSame(2, $result['total']);
        self::assertSame(['ada' => 1, 'Bob' => 1], self::countsByName($result['items']));
        // The last commit inside the range, not Ada's newest commit overall.
        self::assertStringStartsWith('2026-02-10', (string) $result['items'][0]['last_commit_at']);
    }

    public function testSearchesLoginNameAndEmailAndTreatsWildcardsLiterally(): void
    {
        self::assertSame(['carol_c'], self::names($this->contributors(search: 'CAR')['items']));
        self::assertSame(['Bob'], self::names($this->contributors(search: 'bob@')['items']));
        self::assertSame(['ada'], self::names($this->contributors(search: 'lovelace')['items']));
        self::assertSame(0, $this->contributors(search: '%')['total']);
        // "_" would match any character if it were not escaped; only carol_c contains it.
        self::assertSame(['carol_c'], self::names($this->contributors(search: '_')['items']));
    }

    public function testPaginatesWithTheTotalOfAllPages(): void
    {
        $second = $this->contributors(page: 2, perPage: 2);
        $beyond = $this->contributors(page: 5, perPage: 2);

        self::assertSame(['Bob'], self::names($second['items']));
        self::assertSame(3, $second['total']);
        self::assertSame([], $beyond['items']);
        self::assertSame(3, $beyond['total']);
    }

    public function testCommitsOfOneAuthorNewestFirstAndPaginated(): void
    {
        $first = $this->stats->commits($this->repositoryId, $this->adaId, 1, 2);
        $second = $this->stats->commits($this->repositoryId, $this->adaId, 2, 2);

        self::assertSame(3, $first['total']);
        self::assertStringStartsWith('2026-03-10', (string) $first['items'][0]['authored_at']);
        self::assertStringStartsWith('2026-02-10', (string) $first['items'][1]['authored_at']);
        self::assertCount(1, $second['items']);
        self::assertStringStartsWith('2026-01-10', (string) $second['items'][0]['authored_at']);
    }

    /**
     * @param key-of<ContributorStats::SORTS> $sort
     * @param 'asc'|'desc'                    $dir
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function contributors(?string $search = null, ?string $from = null, ?string $to = null, string $sort = 'commits', string $dir = 'desc', int $page = 1, int $perPage = 20): array
    {
        return $this->stats->contributors(
            $this->repositoryId,
            $search,
            $from === null ? null : new \DateTimeImmutable($from.' 00:00 UTC'),
            $to === null ? null : new \DateTimeImmutable($to.' 00:00 UTC'),
            $sort,
            $dir,
            $page,
            $perPage,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_column($items, 'display_name');
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, int>
     */
    private static function countsByName(array $items): array
    {
        return array_map('intval', array_column($items, 'commit_count', 'display_name'));
    }
}
