<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Repository;
use App\ReadModel\ContributorStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Responses match Contributor, Commit and Paginated in source/frontend/src/api/types.ts.
 */
#[AsController]
#[Route('/api/repositories/{id<\d+>}/contributors')]
final class ContributorController
{
    use ApiResponses;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ContributorStats $stats,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Query: search, from and to (YYYY-MM-DD, inclusive, UTC days), sort (commits, name,
     * lastCommit), dir (asc, desc), page, perPage.
     */
    #[Route('', name: 'api_contributors_list', methods: ['GET'])]
    public function list(int $id, Request $request): JsonResponse
    {
        if ($this->entityManager->find(Repository::class, $id) === null) {
            return $this->repositoryNotFound();
        }

        $query = $request->query;
        try {
            $search = trim($query->getString('search'));
            $from = self::day($query, 'from');
            $to = self::day($query, 'to');
            if ($from !== null && $to !== null && $from > $to) {
                throw new \InvalidArgumentException('The start date must not be after the end date.');
            }
            $sort = $query->getString('sort', 'commits');
            if (!isset(ContributorStats::SORTS[$sort])) {
                throw new \InvalidArgumentException(sprintf('Sort by one of: %s.', implode(', ', array_keys(ContributorStats::SORTS))));
            }
            $dir = $query->getString('dir', 'desc');
            if ($dir !== 'asc' && $dir !== 'desc') {
                throw new \InvalidArgumentException('The direction must be asc or desc.');
            }
            [$page, $perPage] = self::paging($query);
        } catch (\InvalidArgumentException $e) {
            return $this->error('invalid_query', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // "to" is the last day included, so the range ends at the start of the next day.
        $result = $this->stats->contributors($id, $search === '' ? null : $search, $from, $to?->modify('+1 day'), $sort, $dir, $page, $perPage);

        return new JsonResponse([
            'items' => array_map(static fn (array $row): array => [
                'authorId' => (int) $row['author_id'],
                'displayName' => $row['display_name'],
                'login' => $row['login'],
                'email' => $row['email'],
                'commitCount' => (int) $row['commit_count'],
                'lastCommitAt' => self::date($row['last_commit_at']),
            ], $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Query: page, perPage. Also returns the author, so the screen can name them.
     */
    #[Route('/{authorId<\d+>}/commits', name: 'api_contributor_commits', methods: ['GET'])]
    public function commits(int $id, int $authorId, Request $request): JsonResponse
    {
        if ($this->entityManager->find(Repository::class, $id) === null) {
            return $this->repositoryNotFound();
        }

        $author = $this->stats->author($authorId);
        if ($author === null) {
            return $this->error('author_not_found', 'This contributor does not exist.', Response::HTTP_NOT_FOUND);
        }

        try {
            [$page, $perPage] = self::paging($request->query);
        } catch (\InvalidArgumentException $e) {
            return $this->error('invalid_query', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->stats->commits($id, $authorId, $page, $perPage);

        return new JsonResponse([
            'author' => [
                'id' => (int) $author['id'],
                'displayName' => $author['display_name'],
                'login' => $author['login'],
                'email' => $author['email'],
            ],
            'items' => array_map(static fn (array $row): array => [
                'sha' => $row['sha'],
                'authoredAt' => self::date($row['authored_at']),
                'authorName' => $row['author_name'],
                'authorEmail' => $row['author_email'],
                'htmlUrl' => $row['html_url'],
            ], $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    /**
     * @param InputBag<string> $query
     *
     * @return array{int, int}
     */
    private static function paging(InputBag $query): array
    {
        $page = filter_var($query->get('page', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var($query->get('perPage', '20'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PER_PAGE]]);

        if ($page === false || $perPage === false) {
            throw new \InvalidArgumentException(sprintf('page must be 1 or more, perPage between 1 and %d.', self::MAX_PER_PAGE));
        }

        return [$page, $perPage];
    }

    /**
     * A YYYY-MM-DD parameter as the start of that day in UTC, or null when absent.
     *
     * @param InputBag<string> $query
     */
    private static function day(InputBag $query, string $name): ?\DateTimeImmutable
    {
        $value = trim($query->getString($name));
        if ($value === '') {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        // The round trip rejects dates PHP would silently roll over, such as 2026-02-31.
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a date in the form YYYY-MM-DD.', $name));
        }

        return $day;
    }
}
