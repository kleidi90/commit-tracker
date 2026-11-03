<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\SyncRun;
use App\Provider\CommitDto;
use App\Provider\CommitProviderInterface;
use App\Provider\ProviderUnavailableException;
use App\Provider\RateLimitedException;
use App\Provider\RepositoryNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs one sync: fetches pages from the provider and stores each page in its own
 * transaction, so a failure on page 3 keeps pages 1 and 2.
 */
final class ImportService
{
    /** Each sync imports up to 1000 of the most recent commits per repository. */
    public const COMMIT_LIMIT = 1000;

    /** @var array<string, CommitProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<CommitProviderInterface> $providers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorIdentityResolver $authors,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('app.commit_provider')]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function run(SyncRun $run): void
    {
        $repository = $run->getRepository();
        $run->markRunning($this->clock->now());
        $this->entityManager->flush();

        try {
            $provider = $this->providers[$repository->getProvider()]
                ?? throw new ProviderUnavailableException(sprintf('No provider is configured for "%s".', $repository->getProvider()));

            foreach ($provider->fetchCommitPages($repository->toRef(), self::COMMIT_LIMIT) as $page) {
                $this->storePage($run, $provider->getName(), $page);
            }

            $run->markSucceeded($this->clock->now());
        } catch (RateLimitedException $e) {
            $run->markPartial(
                $this->clock->now(),
                sprintf('%s Stopped after %d commits; re-sync after the limit resets.', $e->getMessage(), $run->getCommitsFetched()),
                $e->resetAt,
            );
        } catch (RepositoryNotFoundException $e) {
            $run->markFailed($this->clock->now(), $e->getMessage());
        } catch (ProviderUnavailableException $e) {
            $this->stopEarly($run, $e->getMessage());
        } catch (\Throwable $e) {
            // A failed flush closes the entity manager, and then the run cannot be updated here.
            // Rethrow so Messenger logs it; the run counts as stale after SyncStarter::STALE_AFTER.
            if (!$this->entityManager->isOpen()) {
                throw $e;
            }
            $this->logger->error('Import of sync run {id} failed: {message}', ['id' => $run->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
            $this->stopEarly($run, 'The import stopped because of an unexpected error.');
        }

        $this->entityManager->flush();
    }

    /** Partial when earlier pages were stored, failed when nothing was. */
    private function stopEarly(SyncRun $run, string $reason): void
    {
        if ($run->getCommitsFetched() > 0) {
            $run->markPartial($this->clock->now(), $reason);
        } else {
            $run->markFailed($this->clock->now(), $reason);
        }
    }

    /**
     * @param list<CommitDto> $commits
     */
    private function storePage(SyncRun $run, string $provider, array $commits): void
    {
        if ($commits === []) {
            return;
        }

        // A DBAL transaction rather than EntityManager::wrapInTransaction(), which closes
        // the entity manager on any error and would leave the run impossible to update.
        $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($run, $provider, $commits): void {
            $authorIds = $this->authors->resolve($provider, $commits);
            $inserted = $this->insertCommits($connection, (int) $run->getRepository()->getId(), $commits, $authorIds);

            $run->recordPage(count($commits), $inserted);
            $this->entityManager->flush();
        });
    }

    /**
     * One statement per page. ON CONFLICT DO NOTHING makes re-imports idempotent, and the
     * affected row count is exactly the number of commits that were new.
     *
     * @param list<CommitDto> $commits
     * @param list<int>       $authorIds
     */
    private function insertCommits(Connection $connection, int $repositoryId, array $commits, array $authorIds): int
    {
        $values = [];
        $params = [];
        foreach ($commits as $i => $commit) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $repositoryId,
                $authorIds[$i],
                $commit->sha,
                $commit->authoredAt->format('Y-m-d H:i:sP'),
                // Git does not limit these; cut them to the column sizes rather than fail the page.
                mb_substr($commit->authorName, 0, 255),
                mb_substr($commit->authorEmail, 0, 320),
                mb_substr($commit->htmlUrl, 0, 500),
            );
        }

        return (int) $connection->executeStatement(
            'INSERT INTO commits (repository_id, author_id, sha, authored_at, author_name, author_email, html_url)
             VALUES '.implode(', ', $values).'
             ON CONFLICT (repository_id, sha) DO NOTHING',
            $params,
        );
    }
}
