<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * The repository does not exist, or it is private and the token cannot see it.
 * GitHub answers 404 in both cases, so the two cannot be told apart.
 */
final class RepositoryNotFoundException extends \RuntimeException
{
    public static function for(RepoRef $repo, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Repository "%s" was not found or is not accessible.', $repo), 0, $previous);
    }
}
