<?php

declare(strict_types=1);

namespace Phactorial\Tests\Tex;

use Phactorial\Math;
use Phactorial\Render\TexWriter;
use Phactorial\Tests\Fixtures;
use Phactorial\Tests\TreeAssert;
use Phactorial\Tex\TexReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every builder fixture goes node tree to TeX and back to the same tree. */
final class TexRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureNames(): iterable
    {
        foreach (array_keys(Fixtures::all()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtureNames')]
    public function test_fixture_round_trips(string $name): void
    {
        $node = Fixtures::all()[$name];
        $tex = TexWriter::write($node);
        TreeAssert::assertSameTree($node, Math::tex($tex));
        self::assertSame([], TexReader::read($tex)->notes(), $tex);
    }
}
