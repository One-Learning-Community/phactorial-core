<?php

declare(strict_types=1);

namespace Phactorial\Tests\Layout;

use Phactorial\Layout\{Box, Glyph};
use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TexWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FallbackAdvanceTest extends TestCase
{
    /** @return list<Glyph> */
    private static function glyphs(Box $box): array
    {
        return array_values(array_filter($box->items, static fn ($i): bool => $i instanceof Glyph));
    }

    public function test_provider_widens_the_box_and_moves_the_next_glyph(): void
    {
        $plain = Math::layout(Math::text('密度'), 12.0, true, null, false);
        $asked = [];
        $box = Math::layout(Math::text('密度'), 12.0, true, null, false, function (string $char, float $size) use (&$asked): float {
            $asked[] = [$char, $size];
            return 12.0;
        });
        $g = self::glyphs($box);
        $this->assertSame([['密', 12.0], ['度', 12.0]], $asked);
        $this->assertEqualsWithDelta(12.0, $g[1]->x - $g[0]->x, 0.001);
        $this->assertEqualsWithDelta(24.0, $box->width, 0.001);
        $this->assertGreaterThan($plain->width, $box->width);
    }

    public function test_null_keeps_the_notdef_advance_and_covered_characters_are_not_asked(): void
    {
        $plain = Math::layout(Math::text('密x'), 12.0, true, null, false);
        $asked = [];
        $box = Math::layout(Math::text('密x'), 12.0, true, null, false, function (string $char) use (&$asked): ?float {
            $asked[] = $char;
            return null;
        });
        $this->assertSame(['密'], $asked);
        $this->assertEqualsWithDelta($plain->width, $box->width, 0.001);
    }

    public function test_provider_survives_into_scripts(): void
    {
        $box = Math::layout(Math::sup('x', Math::text('密')), 12.0, true, null, false, static fn (string $c, float $s): float => 100.0);
        $this->assertGreaterThan(100.0, $box->width);
    }

    /** @return iterable<string, array{string, string}> */
    public static function droppedBreaks(): iterable
    {
        yield 'inside a fence' => ['\\left( \\\\ = b \\right)', '\\left( = b \\right)'];
        yield 'after an operand in a fence' => ['\\left( a \\\\ = b \\right)', '\\left( a = b \\right)'];
        yield 'two breaks in a row' => ['\\left( \\\\ \\\\ = b \\right)', '\\left( = b \\right)'];
        yield 'after a function name' => ['\\left( \\sin \\\\ ( x ) \\right)', '\\left( \\sin ( x ) \\right)'];
        yield 'after a fraction in a fence' => ['\\left( \\frac{a}{b} \\\\ x \\right)', '\\left( \\frac{a}{b} x \\right)'];
        yield 'fraction then function name' => ['\\left( \\frac{a}{b} \\\\ \\sin x \\right)', '\\left( \\frac{a}{b} \\sin x \\right)'];
        yield 'fraction then relation' => ['\\left( \\frac{a}{b} \\\\ = x \\right)', '\\left( \\frac{a}{b} = x \\right)'];
        yield 'fraction then fraction' => ['\\left( \\frac{a}{b} \\\\ \\frac{a}{b} \\right)', '\\left( \\frac{a}{b} \\frac{a}{b} \\right)'];
    }

    #[DataProvider('droppedBreaks')]
    public function test_dropped_line_break_does_not_change_spacing(string $withBreak, string $without): void
    {
        foreach ([true, false] as $display) {
            $want = Math::layout(Math::tex($without), 18.0, $display)->width;
            $node = Math::tex($withBreak);
            $this->assertEqualsWithDelta($want, Math::layout($node, 18.0, $display)->width, 0.05);
            $viaTex = Math::tex(TexWriter::write($node));
            $this->assertEqualsWithDelta($want, Math::layout($viaTex, 18.0, $display)->width, 0.05);
            $viaMathml = MathmlReader::read(MathmlWriter::write($node))->node;
            $this->assertEqualsWithDelta($want, Math::layout(Math::tex(TexWriter::write($viaMathml)), 18.0, $display)->width, 0.05);
        }
    }

    public function test_contentful_fallback_is_still_an_atom(): void
    {
        // Measured: the unknown command is drawn as its own name and spaced as an ordinary atom before the relation.
        foreach ([true, false] as $display) {
            $this->assertEqualsWithDelta(158.99, Math::layout(Math::tex('\\left( \\unknowncmd = b \\right)'), 18.0, $display)->width, 0.05);
        }
    }
}
