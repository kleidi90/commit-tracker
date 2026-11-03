<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The only thing that knows about a specific Git host. Adding GitLab or Bitbucket
 * means adding one implementation; the import, the schema and the API stay the same.
 */
#[AutoconfigureTag('app.commit_provider')]
interface CommitProviderInterface
{
    /** Short machine name stored in repositories.provider, for example "github". */
    public function getName(): string;

    /**
     * Yields commits newest first, one page at a time, at most $limit commits in total.
     * Pages are yielded separately so each one can be stored in its own transaction:
     * if page 3 fails, pages 1 and 2 are already safe.
     *
     * @return iterable<list<CommitDto>>
     *
     * @throws RepositoryNotFoundException when the repository does not exist or is not visible
     * @throws RateLimitedException        when the provider refuses requests until a reset time
     */
    public function fetchCommitPages(RepoRef $repo, int $limit): iterable;
}
