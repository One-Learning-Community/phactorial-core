<?php

declare(strict_types=1);

namespace Phactorial\Tests\Layout;

use Phactorial\Math;
use Phactorial\Render\SvgRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HyphenMinusTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, bool}> */
    public static function minusCases(): iterable
    {
        foreach ([false, true] as $display) {
            $mode = $display ? 'display' : 'inline';
            yield "prefix, $mode" => ['<math><mo>-</mo><mi>a</mi></math>', '<math><mo>&#x2212;</mo><mi>a</mi></math>', '- a', $display];
            yield "infix, $mode" => ['<math><mi>b</mi><mo>-</mo><mi>a</mi></math>', '<math><mi>b</mi><mo>&#x2212;</mo><mi>a</mi></math>', 'b - a', $display];
            yield "minsize, $mode" => ['<math><mi>b</mi><mo minsize="1em">-</mo><mi>a</mi></math>', '<math><mi>b</mi><mo minsize="1em">&#x2212;</mo><mi>a</mi></math>', null, $display];
            yield "maxsize, $mode" => ['<math><mi>b</mi><mo maxsize="3em">-</mo><mi>a</mi></math>', '<math><mi>b</mi><mo maxsize="3em">&#x2212;</mo><mi>a</mi></math>', null, $display];
            yield "accent, $mode" => ['<math><mover accent="true"><mi>i</mi><mo>-</mo></mover></math>', '<math><mover accent="true"><mi>i</mi><mo>&#x2212;</mo></mover></math>', null, $display];
            yield "underbar, $mode" => ['<math><munder accentunder="true"><mi>i</mi><mo>-</mo></munder></math>', '<math><munder accentunder="true"><mi>i</mi><mo>&#x2212;</mo></munder></math>', null, $display];
        }
    }

    #[DataProvider('minusCases')]
    public function test_hyphen_minus_operator_draws_as_minus(string $hyphen, string $minus, ?string $tex, bool $display): void
    {
        $w = static fn (string $ml): float => Math::layout(Math::mathml($ml), 18.0, $display)->width;
        self::assertEqualsWithDelta($w($minus), $w($hyphen), 0.05);
        if ($tex === null) {
            return;
        }
        self::assertEqualsWithDelta(Math::layout(Math::tex($tex), 18.0, $display)->width, $w($hyphen), 0.05);
    }

    /** @return iterable<string, array{string, float}> */
    public static function elsewhereCases(): iterable
    {
        yield 'identifier' => ['<math><mi>-</mi></math>', 5.67];
        yield 'text' => ['<math><mtext>a-b</mtext></math>', 23.706];
        yield 'multi-character operator' => ['<math><mo>--</mo></math>', 21.34];
    }

    #[DataProvider('elsewhereCases')]
    public function test_hyphen_elsewhere_keeps_its_glyph(string $ml, float $width): void
    {
        foreach ([false, true] as $display) {
            self::assertEqualsWithDelta($width, Math::layout(Math::mathml($ml), 18.0, $display)->width, 0.05);
        }
    }

    public function test_hyphen_minus_renders_the_same_glyph_as_the_minus_sign(): void
    {
        $svg = static fn (string $ml): string => (new SvgRenderer(Math::stixTwoMath(), 'currentColor'))->render(Math::layout(Math::mathml($ml), 18.0, false));
        self::assertSame($svg('<math><mo>&#x2212;</mo><mi>a</mi></math>'), $svg('<math><mo>-</mo><mi>a</mi></math>'));
        self::assertSame($svg('<math><mover accent="true"><mi>i</mi><mo>&#x2212;</mo></mover></math>'), $svg('<math><mover accent="true"><mi>i</mi><mo>-</mo></mover></math>'));
    }
}
