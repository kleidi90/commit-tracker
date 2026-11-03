<?php

declare(strict_types=1);

namespace App\Import;

use App\Provider\CommitDto;

/**
 * Decides which author a commit belongs to, as authors.identity_key.
 *
 * The first rule that applies wins:
 *  1. "<provider>:<account id>" when the provider links the commit to an account.
 *     The numeric id, not the login, because logins can be renamed.
 *  2. "email:<email>" when git recorded an email.
 *  3. "name:<name>" when git recorded a name but no email. Without this, every commit
 *     with an empty email would land on one author that looks like a real person.
 *  4. "unknown" when git recorded neither. Nothing tells these commits apart.
 *
 * The prefixes keep the rules apart: a person named "a@b.com" gets "name:a@b.com",
 * which never collides with the email key "email:a@b.com".
 */
final class AuthorIdentity
{
    public const UNKNOWN = 'unknown';

    public static function keyFor(string $provider, CommitDto $commit): string
    {
        if ($commit->authorExternalId !== null && $commit->authorExternalId !== '') {
            return $provider.':'.$commit->authorExternalId;
        }

        $email = self::normalise($commit->authorEmail);
        if ($email !== '') {
            return 'email:'.$email;
        }

        $name = self::normalise($commit->authorName);
        if ($name !== '') {
            return 'name:'.$name;
        }

        return self::UNKNOWN;
    }

    /**
     * Trimmed, lowercased, inner whitespace collapsed: "John  Smith " and "john smith" match.
     * Cut to 300 characters so the prefixed key always fits authors.identity_key (320);
     * git does not limit names or emails, and one overlong value would fail its page on every sync.
     */
    private static function normalise(string $value): string
    {
        return mb_substr(mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value))), 0, 300);
    }
}
