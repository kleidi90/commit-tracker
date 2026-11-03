<?php

declare(strict_types=1);

namespace App\Entity;

use App\Provider\RepoRef;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'repositories')]
#[ORM\UniqueConstraint(name: 'uniq_repositories_provider_lookup', columns: ['provider', 'lookup_key'])]
class Repository
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    /** Display form, as the user or the provider spelled it. */
    #[ORM\Column(length: 100)]
    private string $owner;

    /** Display form, as the user or the provider spelled it. */
    #[ORM\Column(length: 100)]
    private string $name;

    /**
     * Lowercased "owner/name". GitHub treats repository names case-insensitively,
     * so this column (not owner and name) is what makes a repository unique.
     */
    #[ORM\Column(length: 255)]
    private string $lookupKey;

    /**
     * When the last fully successful sync finished. A partial or failed sync never
     * moves this; the latest attempt, whatever its outcome, lives in sync_runs.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(RepoRef $ref, \DateTimeImmutable $now)
    {
        $this->provider = $ref->provider;
        $this->owner = $ref->owner;
        $this->name = $ref->name;
        $this->lookupKey = $ref->lookupKey();
        $this->createdAt = $now;
    }

    public function markSynced(\DateTimeImmutable $at): void
    {
        $this->lastSyncedAt = $at;
    }

    public function toRef(): RepoRef
    {
        return new RepoRef($this->provider, $this->owner, $this->name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getFullName(): string
    {
        return $this->owner.'/'.$this->name;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }
}
