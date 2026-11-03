<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * One commit, normalised. Every provider (GitHub today, GitLab or Bitbucket later)
 * maps its own API response to this shape, so the import never sees provider JSON.
 */
final readonly class CommitDto
{
    public function __construct(
        public string $sha,
        public \DateTimeImmutable $authoredAt,
        public string $authorName,
        public string $authorEmail,
        public string $htmlUrl,
        /** The provider's numeric account id, or null when the commit is not linked to an account. */
        public ?string $authorExternalId = null,
        public ?string $authorLogin = null,
    ) {
    }
}
