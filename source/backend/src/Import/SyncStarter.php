<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Repository;
use App\Entity\SyncRun;
use App\Enum\SyncStatus;
use App\Message\ImportRepositoryMessage;
use App\Provider\RepoRef;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a sync. The import itself runs in the worker; this only creates the run and the message.
 */
final class SyncStarter
{
    /**
     * An active run with no progress for this long counts as abandoned (for example the
     * worker crashed), so it cannot block its repository forever. A full 1000 commit
     * import takes seconds, so this is generous.
     */
    public const STALE_AFTER = '15 minutes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Adds the repository if it is new (case-insensitively) and queues a sync of it.
     *
     * @throws SyncAlreadyActiveException
     */
    public function startFor(RepoRef $ref): SyncRun
    {
        // Insert-or-ignore, so two people adding the same repository at once both succeed.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO repositories (provider, owner, name, lookup_key, created_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (provider, lookup_key) DO NOTHING',
            [$ref->provider, $ref->owner, $ref->name, $ref->lookupKey(), $this->clock->now()->format('Y-m-d H:i:sP')],
        );

        $repository = $this->entityManager->getRepository(Repository::class)
            ->findOneBy(['provider' => $ref->provider, 'lookupKey' => $ref->lookupKey()])
            ?? throw new \LogicException(sprintf('Repository "%s" was neither inserted nor found.', $ref));

        return $this->start($repository);
    }

    /**
     * @throws SyncAlreadyActiveException
     */
    public function start(Repository $repository): SyncRun
    {
        $now = $this->clock->now();

        $active = $this->entityManager->getRepository(SyncRun::class)->findOneBy([
            'repository' => $repository,
            'status' => [SyncStatus::Queued, SyncStatus::Running],
        ]);

        if ($active !== null) {
            $lastActivity = $active->getStartedAt() ?? $active->getCreatedAt();
            if ($lastActivity > $now->modify('-'.self::STALE_AFTER)) {
                throw SyncAlreadyActiveException::for($repository->getFullName());
            }
            $active->markFailed($now, sprintf('Abandoned: no result after %s, the worker probably stopped.', self::STALE_AFTER));
            // Flushed on its own: Doctrine runs inserts before updates, so in one flush the new
            // run would hit the unique index on active runs before this one stops being active.
            $this->entityManager->flush();
        }

        $run = new SyncRun($repository, $now);

        try {
            // The run and the message commit together: with the Doctrine transport no run is
            // left queued without a message, and no message points at a run that does not exist.
            $this->entityManager->wrapInTransaction(function () use ($run): void {
                $this->entityManager->persist($run);
                $this->entityManager->flush();
                $this->bus->dispatch(new ImportRepositoryMessage((int) $run->getId()));
            });
        } catch (UniqueConstraintViolationException) {
            // Another request queued a run between the check above and this insert;
            // the partial unique index on sync_runs caught it.
            throw SyncAlreadyActiveException::for($repository->getFullName());
        }

        return $run;
    }
}
