<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping only, hence read-only for the ORM. Imports write commits with a single
 * INSERT ... ON CONFLICT (repository_id, sha) DO NOTHING per page: the database guarantees
 * no duplicates, and the number of affected rows is exactly sync_runs.commits_inserted.
 * ContributorStats reads them with SQL. This entity is the schema's source of truth.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'commits')]
#[ORM\UniqueConstraint(name: 'uniq_commits_repository_sha', columns: ['repository_id', 'sha'])]
#[ORM\Index(name: 'idx_commits_repository_author_date', columns: ['repository_id', 'author_id', 'authored_at'])]
#[ORM\Index(name: 'idx_commits_repository_date_author', columns: ['repository_id', 'authored_at', 'author_id'])]
#[ORM\Index(name: 'idx_commits_author', columns: ['author_id'])]
class Commit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repository $repository;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Author $author;

    /** 64 characters so SHA-256 repositories fit, not only today's 40-character SHA-1. */
    #[ORM\Column(length: 64)]
    private string $sha;

    /** Author date, not committer date: a rebase changes the committer date, not when the work was written. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $authoredAt;

    /** Exactly what git recorded on this commit, kept so authors can be regrouped without a re-import. */
    #[ORM\Column(length: 255)]
    private string $authorName;

    /** Exactly what git recorded on this commit. */
    #[ORM\Column(length: 320)]
    private string $authorEmail;

    #[ORM\Column(length: 500)]
    private string $htmlUrl;
}
