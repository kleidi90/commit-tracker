<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Repository;
use App\Import\SyncAlreadyActiveException;
use App\Import\SyncStarter;
use App\Provider\RepoRef;
use App\ReadModel\RepositoryOverview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Responses match Repository and SyncRun in source/frontend/src/api/types.ts.
 */
#[AsController]
#[Route('/api/repositories')]
final class RepositoryController
{
    use ApiResponses;

    public function __construct(
        private readonly RepositoryOverview $overview,
        private readonly SyncStarter $syncStarter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'api_repositories_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map($this->toJson(...), $this->overview->all()));
    }

    #[Route('/{id<\d+>}', name: 'api_repositories_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $row = $this->overview->find($id);

        return $row === null ? $this->repositoryNotFound() : new JsonResponse($this->toJson($row));
    }

    /**
     * Body: {"repository": "owner/repo"}. Adding a repository that already exists
     * (in any letter case) queues a re-sync of it instead of creating a second one.
     */
    #[Route('', name: 'api_repositories_add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        try {
            $input = $request->toArray()['repository'] ?? null;
        } catch (JsonException) {
            return $this->error('invalid_json', 'The request body must be JSON.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_string($input)) {
            return $this->error('invalid_repository', 'Enter a repository as owner/repo.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $ref = RepoRef::fromString($input);
        } catch (\InvalidArgumentException $e) {
            return $this->error('invalid_repository', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->queued(fn () => $this->syncStarter->startFor($ref));
    }

    #[Route('/{id<\d+>}/sync', name: 'api_repositories_sync', methods: ['POST'])]
    public function sync(int $id): JsonResponse
    {
        $repository = $this->entityManager->find(Repository::class, $id);
        if ($repository === null) {
            return $this->repositoryNotFound();
        }

        return $this->queued(fn () => $this->syncStarter->start($repository));
    }

    /**
     * @param callable(): \App\Entity\SyncRun $start
     */
    private function queued(callable $start): JsonResponse
    {
        try {
            $run = $start();
        } catch (SyncAlreadyActiveException $e) {
            return $this->error('sync_already_active', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        $row = $this->overview->find((int) $run->getRepository()->getId());

        return new JsonResponse($this->toJson($row ?? throw new \LogicException('The repository disappeared.')), Response::HTTP_ACCEPTED);
    }

    /**
     * @param array<string, mixed> $row a row from RepositoryOverview
     *
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'provider' => $row['provider'],
            'fullName' => $row['owner'].'/'.$row['name'],
            'commitCount' => (int) $row['commit_count'],
            'lastSyncedAt' => self::date($row['last_synced_at']),
            'latestRun' => $row['run_id'] === null ? null : [
                'id' => (int) $row['run_id'],
                'status' => $row['run_status'],
                'commitsFetched' => (int) $row['run_commits_fetched'],
                'commitsInserted' => (int) $row['run_commits_inserted'],
                'error' => $row['run_error'],
                'rateLimitResetAt' => self::date($row['run_rate_limit_reset_at']),
                'createdAt' => self::date($row['run_created_at']),
                'startedAt' => self::date($row['run_started_at']),
                'finishedAt' => self::date($row['run_finished_at']),
            ],
        ];
    }
}
