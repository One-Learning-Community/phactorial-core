<?php

declare(strict_types=1);

namespace Phactorial\Tests\Layout;

use Phactorial\Math;
use Phactorial\Render\MathmlWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A color switch changes no spacing: an unbraced one splices into its row, a braced group stays an atom as in TeX. */
final class ColorSwitchSpacingTest extends TestCase
{
    private static function width(string $tex): float
    {
        return Math::layout(Math::tex($tex), 12.0, true)->width;
    }

    /** @return iterable<string, array{string, string}> */
    public static function twins(): iterable
    {
        yield 'unbraced switch' => ['a \color{red} + b', 'a + b'];
        yield 'rejected unbraced switch' => ['a \color{Salmon} + b', 'a + b'];
        yield 'braced switch is an atom' => ['a {\color{red} + b}', 'a {+ b}'];
        yield 'rejected braced switch is an atom' => ['a {\color{Salmon} + b}', 'a {+ b}'];
    }

    #[DataProvider('twins')]
    public function test_widths_match_the_uncolored_twin(string $colored, string $plain): void
    {
        self::assertEqualsWithDelta(self::width($plain), self::width($colored), 1e-9);
    }

    public function test_a_rejected_switch_is_not_nested_in_mathml(): void
    {
        $xml = MathmlWriter::write(Math::tex('a \color{Salmon} + b'));
        self::assertSame(MathmlWriter::write(Math::tex('a + b')), $xml);
    }
}
