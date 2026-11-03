<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\SeedsCommits;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Input validation and the response shape. The query itself is covered by ContributorStatsTest.
 */
final class ContributorControllerTest extends WebTestCase
{
    use ResetsDatabase;
    use SeedsCommits;

    private KernelBrowser $client;
    private int $repositoryId;
    private int $authorId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::resetDatabase($connection);

        $this->repositoryId = self::seedRepository($connection, 'acme/widgets');
        $this->authorId = self::seedAuthor($connection, 'github:1', 'ada', 'Ada', 'ada@example.com');
        self::seedCommits($connection, $this->repositoryId, $this->authorId, ['2026-02-01 00:00Z', '2026-02-28 23:30Z']);
    }

    public function testContributorShape(): void
    {
        $body = $this->get(sprintf('/api/repositories/%d/contributors', $this->repositoryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['total' => 1, 'page' => 1, 'perPage' => 20], array_diff_key($body, ['items' => true]));
        self::assertSame([
            'authorId' => $this->authorId,
            'displayName' => 'ada',
            'login' => 'ada',
            'email' => 'ada@example.com',
            'commitCount' => 2,
            'lastCommitAt' => '2026-02-28T23:30:00+00:00',
        ], $body['items'][0]);
    }

    public function testTheEndDateIsInclusive(): void
    {
        $body = $this->get(sprintf('/api/repositories/%d/contributors?from=2026-02-28&to=2026-02-28', $this->repositoryId));

        self::assertSame(1, $body['items'][0]['commitCount']);
    }

    public function testInvalidQueriesAreRejected(): void
    {
        foreach (['sort=sha', 'dir=up', 'from=2026-02-31', 'from=yesterday', 'from=2026-03-01&to=2026-02-01', 'page=0', 'perPage=101', 'page=abc'] as $query) {
            $body = $this->get(sprintf('/api/repositories/%d/contributors?%s', $this->repositoryId, $query));
            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame('invalid_query', $body['error']['code'], $query);
        }
    }

    public function testUnknownRepositoryOrAuthorIsNotFound(): void
    {
        $this->get('/api/repositories/999/contributors');
        self::assertResponseStatusCodeSame(404);

        $body = $this->get(sprintf('/api/repositories/%d/contributors/999/commits', $this->repositoryId));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('author_not_found', $body['error']['code']);
    }

    public function testCommitsShape(): void
    {
        $body = $this->get(sprintf('/api/repositories/%d/contributors/%d/commits?perPage=1', $this->repositoryId, $this->authorId));

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $this->authorId, 'displayName' => 'ada', 'login' => 'ada', 'email' => 'ada@example.com'], $body['author']);
        self::assertSame([2, 1, 1], [$body['total'], $body['page'], $body['perPage']]);
        self::assertSame(['sha', 'authoredAt', 'authorName', 'authorEmail', 'htmlUrl'], array_keys($body['items'][0]));
        self::assertSame('2026-02-28T23:30:00+00:00', $body['items'][0]['authoredAt']);
    }

    /**
     * @return array<mixed>
     */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
