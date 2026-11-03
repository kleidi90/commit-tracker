<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Entity\Repository;
use App\Entity\SyncRun;
use App\Enum\SyncStatus;
use App\Import\AuthorIdentityResolver;
use App\Import\ImportService;
use App\Provider\ProviderUnavailableException;
use App\Provider\RateLimitedException;
use App\Provider\RepoRef;
use App\Provider\RepositoryNotFoundException;
use App\Tests\Support\FakeCommitProvider;
use App\Tests\Support\ResetsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Runs the import against the real app_test database, with a fake provider instead of GitHub.
 */
final class ImportServiceTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private MockClock $clock;
    private FakeCommitProvider $provider;
    private ImportService $importService;
    private Repository $repository;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        self::resetDatabase($this->connection);

        $this->clock = new MockClock('2026-05-01 12:00:00 UTC');
        $this->provider = new FakeCommitProvider();
        $this->importService = new ImportService(
            $this->entityManager,
            $container->get(AuthorIdentityResolver::class),
            $this->clock,
            new NullLogger(),
            [$this->provider],
        );

        $this->repository = new Repository(new RepoRef('github', 'acme', 'widgets'), $this->clock->now());
        $this->entityManager->persist($this->repository);
        $this->entityManager->flush();
    }

    public function testReimportingTheSameCommitsLeavesCommitAndAuthorCountsUnchanged(): void
    {
        $this->provider->willServe(FakeCommitProvider::pages(2));

        $first = $this->import();
        $commitsAfterFirst = self::rowCount($this->connection, 'commits');
        $authorsAfterFirst = self::rowCount($this->connection, 'authors');

        $second = $this->import();

        self::assertSame(6, $commitsAfterFirst);
        self::assertSame(3, $authorsAfterFirst);
        self::assertSame($commitsAfterFirst, self::rowCount($this->connection, 'commits'));
        self::assertSame($authorsAfterFirst, self::rowCount($this->connection, 'authors'));

        self::assertSame([SyncStatus::Succeeded, 6, 6], [$first->getStatus(), $first->getCommitsFetched(), $first->getCommitsInserted()]);
        // The re-sync visibly fetched everything and found nothing new.
        self::assertSame([SyncStatus::Succeeded, 6, 0], [$second->getStatus(), $second->getCommitsFetched(), $second->getCommitsInserted()]);
    }

    public function testResyncInsertsOnlyNewCommits(): void
    {
        $this->provider->willServe(FakeCommitProvider::pages(1));
        $this->import();

        $pages = FakeCommitProvider::pages(1);
        $pages[0][] = FakeCommitProvider::commit('new');
        $this->provider->willServe($pages);
        $run = $this->import();

        self::assertSame([4, 1], [$run->getCommitsFetched(), $run->getCommitsInserted()]);
        self::assertSame(4, self::rowCount($this->connection, 'commits'));
    }

    public function testRateLimitOnPage3KeepsPages1And2AndDoesNotMoveLastSyncedAt(): void
    {
        $this->provider->willServe(FakeCommitProvider::pages(1, prefix: 'old'));
        $this->import();
        $lastSynced = $this->repository->getLastSyncedAt();

        $this->clock->sleep(3600);
        $resetAt = new \DateTimeImmutable('2026-05-01 14:00:00 UTC');
        $this->provider->willServe(FakeCommitProvider::pages(2), new RateLimitedException($resetAt));
        $run = $this->import();

        self::assertSame(SyncStatus::Partial, $run->getStatus());
        self::assertEquals($resetAt, $run->getRateLimitResetAt());
        self::assertSame([6, 6], [$run->getCommitsFetched(), $run->getCommitsInserted()]);
        self::assertStringContainsString('Stopped after 6 commits', (string) $run->getError());
        self::assertSame(3 + 6, self::rowCount($this->connection, 'commits'));

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Repository::class, $this->repository->getId());
        self::assertEquals($lastSynced, $stored?->getLastSyncedAt());
    }

    public function testRateLimitOnTheFirstSyncLeavesLastSyncedAtEmpty(): void
    {
        $this->provider->willServe(FakeCommitProvider::pages(2), new RateLimitedException(new \DateTimeImmutable('2026-05-01 14:00:00 UTC')));

        $run = $this->import();

        self::assertSame(SyncStatus::Partial, $run->getStatus());
        self::assertNull($this->repository->getLastSyncedAt());
    }

    public function testSuccessMovesLastSyncedAt(): void
    {
        $this->provider->willServe(FakeCommitProvider::pages(1));

        $this->import();

        self::assertEquals($this->clock->now(), $this->repository->getLastSyncedAt());
    }

    public function testUnknownRepositoryFails(): void
    {
        $this->provider->willServe([], RepositoryNotFoundException::for($this->repository->toRef()));

        $run = $this->import();

        self::assertSame(SyncStatus::Failed, $run->getStatus());
        self::assertStringContainsString('acme/widgets', (string) $run->getError());
        self::assertNotNull($run->getFinishedAt());
    }

    public function testProviderErrorIsPartialAfterStoredPagesAndFailedBeforeAny(): void
    {
        $this->provider->willServe(FakeCommitProvider::pages(1), new ProviderUnavailableException('GitHub answered with HTTP 502.'));
        $partial = $this->import();

        $this->provider->willServe([], new ProviderUnavailableException('GitHub answered with HTTP 502.'));
        $failed = $this->import();

        self::assertSame(SyncStatus::Partial, $partial->getStatus());
        self::assertNull($partial->getRateLimitResetAt());
        self::assertSame(SyncStatus::Failed, $failed->getStatus());
        self::assertSame('GitHub answered with HTTP 502.', $failed->getError());
    }

    public function testOneAccountUnderTwoEmailsIsOneAuthorButUnlinkedEmailsAreTwo(): void
    {
        $this->provider->willServe([[
            FakeCommitProvider::commit('a', 'Ada', 'ada@work.example', '42', 'ada'),
            FakeCommitProvider::commit('b', 'Ada', 'ada@home.example', '42', 'ada'),
            FakeCommitProvider::commit('c', 'Bob', 'bob@work.example'),
            FakeCommitProvider::commit('d', 'Bob', 'bob@home.example'),
            FakeCommitProvider::commit('e', 'Bob', 'BOB@work.example '),
        ]]);

        $this->import();

        self::assertSame(
            ['email:bob@home.example' => 1, 'email:bob@work.example' => 2, 'github:42' => 2],
            $this->connection->fetchAllKeyValue(
                'SELECT a.identity_key, count(*) FROM commits c JOIN authors a ON a.id = c.author_id GROUP BY a.identity_key ORDER BY a.identity_key',
            ),
        );
    }

    public function testKeepsTheNewestDisplayNameAndFollowsLoginRenames(): void
    {
        // Newest first, as providers deliver them.
        $this->provider->willServe([[FakeCommitProvider::commit('new', 'Ada Lovelace', 'ada@example.com', '42', 'ada')], [FakeCommitProvider::commit('old', 'ada', 'ada@example.com', '42', 'ada')]]);
        $this->import();

        $this->provider->willServe([[FakeCommitProvider::commit('newer', 'Ada Lovelace', 'ada@example.com', '42', 'ada-renamed')]]);
        $this->import();

        self::assertSame(
            ['login' => 'ada-renamed', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'external_id' => '42'],
            $this->connection->fetchAssociative('SELECT login, name, email, external_id FROM authors'),
        );
    }

    private function import(): SyncRun
    {
        $run = new SyncRun($this->repository, $this->clock->now());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->importService->run($run);

        return $run;
    }
}
