<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response conventions every API controller shares (see CLAUDE.md, "API").
 */
trait ApiResponses
{
    /** {"error": {"code": "...", "message": "..."}}; the message is safe to show to the user. */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function repositoryNotFound(): JsonResponse
    {
        return $this->error('repository_not_found', 'This repository does not exist.', Response::HTTP_NOT_FOUND);
    }

    /** Database timestamps as ISO 8601 with offset, which the browser's Date parses. */
    private static function date(mixed $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable((string) $value))->format(\DATE_ATOM);
    }
}
