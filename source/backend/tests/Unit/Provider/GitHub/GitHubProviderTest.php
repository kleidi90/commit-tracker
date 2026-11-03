<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider\GitHub;

use App\Provider\CommitDto;
use App\Provider\GitHub\GitHubProvider;
use App\Provider\ProviderUnavailableException;
use App\Provider\RateLimitedException;
use App\Provider\RepoRef;
use App\Provider\RepositoryNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubProviderTest extends TestCase
{
    public function testMapsTheGitHubResponseToCommitDtos(): void
    {
        $client = new MockHttpClient([self::json([
            self::item('a1', accountId: 583231, login: 'octocat'),
            // Not linked to any GitHub account: "author" is null.
            self::item('b2', name: 'Ghost', email: 'ghost@example.com', accountId: null),
        ])], 'https://api.github.com');

        $pages = self::fetchAll(new GitHubProvider($client, new MockClock()), 1000);

        self::assertCount(1, $pages);
        [$linked, $unlinked] = $pages[0];

        self::assertSame('a1', $linked->sha);
        self::assertEquals(new \DateTimeImmutable('2026-03-01T10:00:00Z'), $linked->authoredAt);
        self::assertSame('Mona', $linked->authorName);
        self::assertSame('mona@example.com', $linked->authorEmail);
        self::assertSame('https://github.com/acme/widgets/commit/a1', $linked->htmlUrl);
        self::assertSame('583231', $linked->authorExternalId);
        self::assertSame('octocat', $linked->authorLogin);

        self::assertNull($unlinked->authorExternalId);
        self::assertNull($unlinked->authorLogin);
        self::assertSame('ghost@example.com', $unlinked->authorEmail);
    }

    public function testRequestsPagesUntilTheLimitAndSendsTheToken(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'headers' => $options['headers']];

            return self::json(array_map(static fn (int $i) => self::item('c'.$i), range(1, 100)));
        }, 'https://api.github.com');

        $pages = self::fetchAll(new GitHubProvider($client, new MockClock(), 'secret-token'), 250);

        self::assertSame([100, 100, 50], array_map('count', $pages));
        self::assertCount(3, $requests);
        self::assertStringEndsWith('/repos/acme/widgets/commits?per_page=100&page=3', $requests[2]['url']);
        self::assertContains('Authorization: Bearer secret-token', $requests[0]['headers']);
    }

    public function testStopsAfterAShortPage(): void
    {
        $client = new MockHttpClient([
            self::json(array_map(static fn (int $i) => self::item('c'.$i), range(1, 100))),
            self::json([self::item('last')]),
        ], 'https://api.github.com');

        // A third request would make MockHttpClient throw, so this also proves none is made.
        self::assertSame([100, 1], array_map('count', self::fetchAll(new GitHubProvider($client, new MockClock()), 1000)));
    }

    public function testEmptyRepositoryYieldsNoPages(): void
    {
        $client = new MockHttpClient([self::json(['message' => 'Git Repository is empty.'], 409)], 'https://api.github.com');

        self::assertSame([], self::fetchAll(new GitHubProvider($client, new MockClock()), 1000));
    }

    public function testNotFound(): void
    {
        $client = new MockHttpClient([self::json(['message' => 'Not Found'], 404)], 'https://api.github.com');

        $this->expectException(RepositoryNotFoundException::class);
        self::fetchAll(new GitHubProvider($client, new MockClock()), 1000);
    }

    public function testPrimaryRateLimitCarriesTheResetTime(): void
    {
        $client = new MockHttpClient([self::json(['message' => 'API rate limit exceeded'], 403, [
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '1780000000',
        ])], 'https://api.github.com');

        try {
            self::fetchAll(new GitHubProvider($client, new MockClock()), 1000);
            self::fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $e) {
            self::assertSame(1780000000, $e->resetAt->getTimestamp());
        }
    }

    public function testSecondaryRateLimitUsesRetryAfter(): void
    {
        $clock = new MockClock('2026-05-01 12:00:00 UTC');
        $client = new MockHttpClient([self::json(['message' => 'secondary rate limit'], 429, ['retry-after' => '60'])], 'https://api.github.com');

        try {
            self::fetchAll(new GitHubProvider($client, $clock), 1000);
            self::fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $e) {
            self::assertEquals(new \DateTimeImmutable('2026-05-01 12:01:00 UTC'), $e->resetAt);
        }
    }

    public function testForbiddenWithoutRateLimitHeadersIsNotARateLimit(): void
    {
        $client = new MockHttpClient([self::json(['message' => 'Forbidden'], 403, ['x-ratelimit-remaining' => '42'])], 'https://api.github.com');

        $this->expectException(ProviderUnavailableException::class);
        self::fetchAll(new GitHubProvider($client, new MockClock()), 1000);
    }

    public function testServerErrorAndUnreadableBodyBecomeProviderUnavailable(): void
    {
        foreach ([new MockResponse('oops', ['http_code' => 502]), new MockResponse('not json', ['http_code' => 200])] as $response) {
            $client = new MockHttpClient([$response], 'https://api.github.com');
            try {
                self::fetchAll(new GitHubProvider($client, new MockClock()), 1000);
                self::fail('Expected a ProviderUnavailableException.');
            } catch (ProviderUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return list<list<CommitDto>>
     */
    private static function fetchAll(GitHubProvider $provider, int $limit): array
    {
        return iterator_to_array($provider->fetchCommitPages(new RepoRef('github', 'acme', 'widgets'), $limit), false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $sha, string $name = 'Mona', string $email = 'mona@example.com', ?int $accountId = 1, string $login = 'mona'): array
    {
        return [
            'sha' => $sha,
            'html_url' => 'https://github.com/acme/widgets/commit/'.$sha,
            'commit' => [
                'author' => ['name' => $name, 'email' => $email, 'date' => '2026-03-01T10:00:00Z'],
                'committer' => ['name' => 'GitHub', 'email' => 'noreply@github.com', 'date' => '2026-03-02T10:00:00Z'],
                'message' => 'Change things',
            ],
            'author' => $accountId === null ? null : ['id' => $accountId, 'login' => $login],
        ];
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    private static function json(array $body, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'] + $headers,
        ]);
    }
}
