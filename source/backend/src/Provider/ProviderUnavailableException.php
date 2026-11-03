<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * The provider could not be reached, answered with an unexpected status, or sent
 * something unreadable. The message is safe to show to the user.
 */
final class ProviderUnavailableException extends \RuntimeException
{
}
