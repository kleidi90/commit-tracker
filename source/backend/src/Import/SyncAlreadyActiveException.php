<?php

declare(strict_types=1);

namespace App\Import;

final class SyncAlreadyActiveException extends \RuntimeException
{
    public static function for(string $repository): self
    {
        return new self(sprintf('A sync of "%s" is already queued or running.', $repository));
    }
}
