<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SyncRun;
use App\Import\ImportService;
use App\Message\ImportRepositoryMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportRepositoryHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportService $importService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportRepositoryMessage $message): void
    {
        $run = $this->entityManager->find(SyncRun::class, $message->syncRunId);

        // Gone (repository deleted) or already finished (for example marked abandoned):
        // nothing to do, and retrying would not change that.
        if ($run === null || !$run->isActive()) {
            $this->logger->info('Skipping sync run {id}: it no longer exists or is not active.', ['id' => $message->syncRunId]);

            return;
        }

        // A run that is already "running" was picked up by a worker that died; importing
        // again is safe because every write is idempotent.
        $this->importService->run($run);
    }
}
