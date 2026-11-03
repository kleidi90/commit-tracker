<?php

declare(strict_types=1);

namespace App\Provider;

final class RateLimitedException extends \RuntimeException
{
    public function __construct(
        public readonly \DateTimeImmutable $resetAt,
        string $message = 'The provider rate limit was reached.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
