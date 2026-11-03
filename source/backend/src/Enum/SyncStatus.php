<?php

declare(strict_types=1);

namespace App\Enum;

enum SyncStatus: string
{
    /** Created by the API, waiting for the worker. */
    case Queued = 'queued';

    /** The worker is fetching and storing pages. */
    case Running = 'running';

    /** Every page was fetched and stored. Only this status updates repositories.last_synced_at. */
    case Succeeded = 'succeeded';

    /** Stopped early (for example rate limited). Pages stored before the stop are kept. */
    case Partial = 'partial';

    /** Nothing usable happened, for example the repository does not exist. */
    case Failed = 'failed';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
