<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Message\ImportRepositoryMessage;
use App\Tests\Support\ResetsDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The HTTP contract the frontend relies on: status codes, error shape and response fields.
 * Messages go to the in-memory transport, so no import (and no GitHub call) runs.
 */
final class RepositoryControllerTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase(self::getContainer()->get(Connection::class));
    }

    public function testAddingARepositoryQueuesAnImport(): void
    {
        $body = $this->post('/api/repositories', ['repository' => 'acme/widgets']);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('acme/widgets', $body['fullName']);
        self::assertSame('github', $body['provider']);
        self::assertSame(0, $body['commitCount']);
        self::assertNull($body['lastSyncedAt']);
        self::assertSame('queued', $body['latestRun']['status']);
        self::assertSame(['id', 'status', 'commitsFetched', 'commitsInserted', 'error', 'rateLimitResetAt', 'createdAt', 'startedAt', 'finishedAt'], array_keys($body['latestRun']));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(ImportRepositoryMessage::class, $sent[0]->getMessage());
    }

    public function testAddingWhileASyncIsActiveIsAConflict(): void
    {
        $this->post('/api/repositories', ['repository' => 'acme/widgets']);
        $body = $this->post('/api/repositories', ['repository' => 'ACME/Widgets']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sync_already_active', $body['error']['code']);
    }

    public function testInvalidInputIsRejected(): void
    {
        foreach ([['repository' => 'not a repo'], ['repository' => 42], []] as $payload) {
            $body = $this->post('/api/repositories', $payload);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_repository', $body['error']['code']);
            self::assertIsString($body['error']['message']);
        }

        $this->client->request('POST', '/api/repositories', server: ['CONTENT_TYPE' => 'application/json'], content: '{nope');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_json', $this->json()['error']['code']);
    }

    public function testListAndShow(): void
    {
        $this->post('/api/repositories', ['repository' => 'zeta/last']);
        $added = $this->post('/api/repositories', ['repository' => 'acme/widgets']);

        $this->client->request('GET', '/api/repositories');
        self::assertResponseIsSuccessful();
        self::assertSame(['acme/widgets', 'zeta/last'], array_column($this->json(), 'fullName'));

        $this->client->request('GET', '/api/repositories/'.$added['id']);
        self::assertResponseIsSuccessful();
        self::assertSame($added, $this->json());
    }

    public function testResyncOfAnUnknownRepositoryIsNotFound(): void
    {
        $body = $this->post('/api/repositories/999/sync', null);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('repository_not_found', $body['error']['code']);

        $this->client->request('GET', '/api/repositories/999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testResyncWhileActiveIsAConflict(): void
    {
        $added = $this->post('/api/repositories', ['repository' => 'acme/widgets']);
        $body = $this->post('/api/repositories/'.$added['id'].'/sync', null);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sync_already_active', $body['error']['code']);
    }

    public function testResyncAfterTheLastRunFinishedQueuesANewRun(): void
    {
        $added = $this->post('/api/repositories', ['repository' => 'acme/widgets']);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE sync_runs SET status = 'succeeded'");

        $body = $this->post('/api/repositories/'.$added['id'].'/sync', null);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $body['latestRun']['status']);
        self::assertGreaterThan($added['latestRun']['id'], $body['latestRun']['id']);
    }

    /**
     * @param array<mixed>|null $payload
     *
     * @return array<mixed>
     */
    private function post(string $uri, ?array $payload): array
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $payload === null ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->json();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
