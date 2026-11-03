<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Carries only the sync run id. The worker reloads everything else from the database,
 * so a message that sits in the queue never acts on stale data.
 */
final readonly class ImportRepositoryMessage
{
    public function __construct(
        public int $syncRunId,
    ) {
    }
}
