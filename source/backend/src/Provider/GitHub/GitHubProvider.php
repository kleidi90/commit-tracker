<?php

declare(strict_types=1);

namespace App\Provider\GitHub;

use App\Provider\CommitDto;
use App\Provider\CommitProviderInterface;
use App\Provider\ProviderUnavailableException;
use App\Provider\RateLimitedException;
use App\Provider\RepoRef;
use App\Provider\RepositoryNotFoundException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads commits from GET /repos/{owner}/{repo}/commits: the default branch, newest first.
 * The only class that knows what GitHub's JSON looks like.
 */
final class GitHubProvider implements CommitProviderInterface
{
    public const NAME = 'github';

    /** The largest page GitHub serves. 1000 commits cost 10 requests. */
    private const PER_PAGE = 100;

    public function __construct(
        private readonly HttpClientInterface $githubClient,
        private readonly ClockInterface $clock,
        #[Autowire('%app.github_token%')]
        private readonly string $token = '',
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function fetchCommitPages(RepoRef $repo, int $limit): iterable
    {
        $perPage = min(self::PER_PAGE, $limit);
        $remaining = $limit;

        for ($page = 1; $remaining > 0; ++$page) {
            $items = $this->requestPage($repo, $page, $perPage);
            $commits = array_map($this->mapCommit(...), array_slice($items, 0, $remaining));

            if ($commits !== []) {
                yield $commits;
            }

            $remaining -= count($commits);

            // A short page is the last one; asking for the next would only cost a request.
            if (count($items) < $perPage) {
                return;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestPage(RepoRef $repo, int $page, int $perPage): array
    {
        $options = ['query' => ['per_page' => $perPage, 'page' => $page]];
        if ($this->token !== '') {
            $options['auth_bearer'] = $this->token;
        }

        try {
            $response = $this->githubClient->request(
                'GET',
                sprintf('/repos/%s/%s/commits', rawurlencode($repo->owner), rawurlencode($repo->name)),
                $options,
            );
            $status = $response->getStatusCode();

            if ($status === 200) {
                $items = $response->toArray();
                if (!array_is_list($items)) {
                    throw new ProviderUnavailableException('GitHub sent an unexpected response for the commit list.');
                }

                return $items;
            }

            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new ProviderUnavailableException('GitHub could not be reached or sent an unreadable response.', 0, $e);
        }

        // GitHub answers 409 for a repository without any commits yet.
        if ($status === 409) {
            return [];
        }

        throw $this->failureFor($repo, $status, $headers);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function failureFor(RepoRef $repo, int $status, array $headers): \RuntimeException
    {
        if ($status === 404) {
            return RepositoryNotFoundException::for($repo);
        }

        if ($status === 403 || $status === 429) {
            // Primary limit: the hourly quota is used up until X-RateLimit-Reset (epoch seconds).
            if (($headers['x-ratelimit-remaining'][0] ?? null) === '0' && isset($headers['x-ratelimit-reset'][0])) {
                return new RateLimitedException(new \DateTimeImmutable('@'.(int) $headers['x-ratelimit-reset'][0]), 'GitHub rate limit reached.');
            }

            // Secondary limit: too many requests in a short time, wait Retry-After seconds.
            if (isset($headers['retry-after'][0])) {
                $resetAt = $this->clock->now()->modify(sprintf('+%d seconds', (int) $headers['retry-after'][0]));

                return new RateLimitedException($resetAt, 'GitHub secondary rate limit reached.');
            }
        }

        if ($status === 401) {
            return new ProviderUnavailableException('GitHub rejected the configured token. Check GITHUB_TOKEN.');
        }

        return new ProviderUnavailableException(sprintf('GitHub answered with HTTP %d.', $status));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function mapCommit(array $item): CommitDto
    {
        $sha = $item['sha'] ?? null;
        $gitAuthor = is_array($item['commit']['author'] ?? null) ? $item['commit']['author'] : [];
        // The GitHub account the commit is linked to; null when no account matches the email.
        $account = is_array($item['author'] ?? null) ? $item['author'] : [];

        if (!is_string($sha) || !is_string($gitAuthor['date'] ?? null)) {
            throw new ProviderUnavailableException('GitHub sent a commit without a hash or author date.');
        }

        try {
            $authoredAt = new \DateTimeImmutable($gitAuthor['date']);
        } catch (\Exception $e) {
            throw new ProviderUnavailableException(sprintf('GitHub sent an unreadable date for commit %s.', $sha), 0, $e);
        }

        return new CommitDto(
            sha: $sha,
            authoredAt: $authoredAt,
            authorName: (string) ($gitAuthor['name'] ?? ''),
            authorEmail: (string) ($gitAuthor['email'] ?? ''),
            htmlUrl: (string) ($item['html_url'] ?? ''),
            authorExternalId: isset($account['id']) ? (string) $account['id'] : null,
            authorLogin: isset($account['login']) ? (string) $account['login'] : null,
        );
    }
}
