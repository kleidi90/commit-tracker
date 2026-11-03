<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\RepoRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepoRefTest extends TestCase
{
    public function testParsesOwnerAndName(): void
    {
        $ref = RepoRef::fromString('  Symfony/symfony ');

        self::assertSame('github', $ref->provider);
        self::assertSame('Symfony', $ref->owner);
        self::assertSame('symfony', $ref->name);
    }

    public function testLookupKeyIsCaseInsensitive(): void
    {
        self::assertSame(
            RepoRef::fromString('symfony/symfony')->lookupKey(),
            RepoRef::fromString('Symfony/Symfony')->lookupKey(),
        );
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RepoRef::fromString($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'no slash' => ['symfony'];
        yield 'too many parts' => ['symfony/symfony/extra'];
        yield 'full url' => ['https://github.com/symfony/symfony'];
        yield 'dot dot' => ['symfony/..'];
        yield 'owner starts with dash' => ['-symfony/symfony'];
    }
}
