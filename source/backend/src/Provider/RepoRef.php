<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * A provider-agnostic pointer to a repository: "github" + "symfony" + "symfony".
 */
final readonly class RepoRef
{
    private const OWNER_PATTERN = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})';
    private const NAME_PATTERN = '[A-Za-z0-9._-]{1,100}';

    public function __construct(
        public string $provider,
        public string $owner,
        public string $name,
    ) {
    }

    /**
     * Parses user input such as "symfony/symfony".
     *
     * @throws \InvalidArgumentException when the input is not "owner/repo"
     */
    public static function fromString(string $input, string $provider = 'github'): self
    {
        $input = trim($input);
        $pattern = '~^('.self::OWNER_PATTERN.')/('.self::NAME_PATTERN.')$~';

        if (!preg_match($pattern, $input, $matches) || $matches[2] === '.' || $matches[2] === '..') {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid repository. Use the form owner/repo.', $input));
        }

        return new self($provider, $matches[1], $matches[2]);
    }

    /** Case-insensitive identity, stored as repositories.lookup_key. */
    public function lookupKey(): string
    {
        return mb_strtolower($this->owner.'/'.$this->name);
    }

    public function __toString(): string
    {
        return $this->owner.'/'.$this->name;
    }
}
