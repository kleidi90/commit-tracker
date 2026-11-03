<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SyncStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One import attempt for one repository. The history of attempts is what lets the
 * interface show "rate limited until 14:05" while last_synced_at stays truthful.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sync_runs')]
#[ORM\Index(name: 'idx_sync_runs_repository', columns: ['repository_id'])]
#[ORM\UniqueConstraint(
    name: 'uniq_sync_runs_active',
    columns: ['repository_id'],
    options: ['where' => self::ACTIVE_RUN_PREDICATE],
)]
class SyncRun
{
    /**
     * Partial unique index: at most one queued or running run per repository, so a
     * double click on "re-sync" cannot start two imports of the same repository.
     *
     * Written exactly the way PostgreSQL prints the predicate back. Doctrine compares
     * the strings, so any other spelling shows up as a schema diff on every run.
     */
    public const ACTIVE_RUN_PREDICATE = "((status)::text = ANY ((ARRAY['queued'::character varying, 'running'::character varying])::text[]))";

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repository $repository;

    #[ORM\Column(length: 16, enumType: SyncStatus::class)]
    private SyncStatus $status = SyncStatus::Queued;

    /** Commits received from the provider, duplicates included. */
    #[ORM\Column]
    private int $commitsFetched = 0;

    /** Commits that were new. A re-sync of an unchanged repository shows 0 here. */
    #[ORM\Column]
    private int $commitsInserted = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rateLimitResetAt = null;

    /** When the run was queued. A run stuck in "queued" has no started_at, so stale detection needs this. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Repository $repository, \DateTimeImmutable $now)
    {
        $this->repository = $repository;
        $this->createdAt = $now;
    }

    public function markRunning(\DateTimeImmutable $now): void
    {
        $this->status = SyncStatus::Running;
        $this->startedAt = $now;
    }

    public function recordPage(int $fetched, int $inserted): void
    {
        $this->commitsFetched += $fetched;
        $this->commitsInserted += $inserted;
    }

    public function markSucceeded(\DateTimeImmutable $now): void
    {
        $this->finish(SyncStatus::Succeeded, $now);
        $this->repository->markSynced($now);
    }

    public function markPartial(\DateTimeImmutable $now, string $reason, ?\DateTimeImmutable $rateLimitResetAt = null): void
    {
        $this->finish(SyncStatus::Partial, $now);
        $this->error = $reason;
        $this->rateLimitResetAt = $rateLimitResetAt;
    }

    public function markFailed(\DateTimeImmutable $now, string $reason): void
    {
        $this->finish(SyncStatus::Failed, $now);
        $this->error = $reason;
    }

    private function finish(SyncStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->finishedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }

    public function getStatus(): SyncStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function getCommitsFetched(): int
    {
        return $this->commitsFetched;
    }

    public function getCommitsInserted(): int
    {
        return $this->commitsInserted;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRateLimitResetAt(): ?\DateTimeImmutable
    {
        return $this->rateLimitResetAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
