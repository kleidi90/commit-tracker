<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\AuthorIdentity;
use App\Provider\CommitDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorIdentityTest extends TestCase
{
    #[DataProvider('commits')]
    public function testPicksTheFirstRuleThatApplies(CommitDto $commit, string $expected): void
    {
        self::assertSame($expected, AuthorIdentity::keyFor('github', $commit));
    }

    /** @return iterable<string, array{CommitDto, string}> */
    public static function commits(): iterable
    {
        yield 'linked account wins over email' => [self::commit('John', 'john@example.com', '42'), 'github:42'];
        yield 'email is lowercased and trimmed' => [self::commit('John', ' John@Example.COM '), 'email:john@example.com'];
        yield 'blank email falls back to name' => [self::commit('John  Smith ', '   '), 'name:john smith'];
        yield 'empty email falls back to name' => [self::commit('John', ''), 'name:john'];
        yield 'empty account id is ignored' => [self::commit('John', 'john@example.com', ''), 'email:john@example.com'];
        yield 'no account, email or name' => [self::commit(' ', ''), AuthorIdentity::UNKNOWN];
    }

    public function testNameThatLooksLikeAnEmailDoesNotCollideWithTheEmailKey(): void
    {
        self::assertNotSame(
            AuthorIdentity::keyFor('github', self::commit('a@b.com', '')),
            AuthorIdentity::keyFor('github', self::commit('Someone', 'a@b.com')),
        );
    }

    public function testOverlongValuesStillFitTheIdentityKeyColumn(): void
    {
        $email = str_repeat('a', 400).'@example.com';

        self::assertLessThanOrEqual(320, mb_strlen(AuthorIdentity::keyFor('github', self::commit('John', $email))));
        self::assertLessThanOrEqual(320, mb_strlen(AuthorIdentity::keyFor('github', self::commit(str_repeat('n', 400), ''))));
    }

    private static function commit(string $name, string $email, ?string $externalId = null): CommitDto
    {
        return new CommitDto(
            sha: str_repeat('a', 40),
            authoredAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            authorName: $name,
            authorEmail: $email,
            htmlUrl: 'https://github.com/o/r/commit/'.str_repeat('a', 40),
            authorExternalId: $externalId,
        );
    }
}
