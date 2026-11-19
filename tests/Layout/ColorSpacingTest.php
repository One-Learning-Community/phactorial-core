<?php

declare(strict_types=1);

namespace Phactorial\Tests\Layout;

use Phactorial\Layout\Box;
use Phactorial\Layout\Glyph;
use Phactorial\Layout\Rule;
use Phactorial\Math;
use Phactorial\Node\Color;
use Phactorial\Node\Fenced;
use Phactorial\Node\Identifier;
use Phactorial\Node\Middle;
use Phactorial\Node\Node;
use Phactorial\Node\Number;
use Phactorial\Node\Operator;
use Phactorial\Node\Row;
use Phactorial\Node\TexSource;
use Phactorial\Node\UnderOver;
use PHPUnit\Framework\TestCase;

final class ColorSpacingTest extends TestCase
{
    private const RED = '#ff0000';

    /** @return list<array{x: float, y: float, gid: int, color: ?string}> */
    private static function glyphs(Box $b): array
    {
        $out = [];
        $b->each(static function ($item, float $dx, float $dy) use (&$out): void {
            if ($item instanceof Glyph) {
                $out[] = ['x' => $item->x + $dx, 'y' => $item->y + $dy, 'gid' => $item->gid, 'color' => $item->color];
            }
        });

        return $out;
    }

    private static function plus(): Operator
    {
        return new Operator('+');
    }

    public function test_colored_binary_operator_keeps_spacing(): void
    {
        $plain = Math::layout(new Row([new Identifier('a'), self::plus(), new Identifier('b')]), 12.0, true);
        $colored = Math::layout(new Row([new Identifier('a'), new Color(self::plus(), self::RED), new Identifier('b')]), 12.0, true);

        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);
        $p = self::glyphs($plain);
        $c = self::glyphs($colored);
        self::assertCount(count($p), $c);
        foreach ($p as $i => $g) {
            self::assertEqualsWithDelta($g['x'], $c[$i]['x'], 1e-9);
        }
    }

    public function test_colored_operator_is_colored(): void
    {
        $box = Math::layout(new Row([new Identifier('a'), new Color(self::plus(), self::RED), new Identifier('b')]), 12.0, true);

        $colors = array_map(static fn (array $g): ?string => $g['color'], self::glyphs($box));
        self::assertSame([null, self::RED, null], $colors);
    }

    public function test_colored_sum_keeps_limits(): void
    {
        $limits = static fn (Node $base): Box => Math::layout(new UnderOver($base, new Identifier('i'), new Identifier('n')), 12.0, true);
        $plain = $limits(new Operator('∑'));
        $colored = $limits(new Color(new Operator('∑'), self::RED));

        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);
        self::assertEqualsWithDelta($plain->ascent, $colored->ascent, 1e-9);
        self::assertEqualsWithDelta($plain->descent, $colored->descent, 1e-9);
        $p = self::glyphs($plain);
        $c = self::glyphs($colored);
        self::assertCount(3, $c);
        self::assertSame(self::RED, $c[0]['color']);
        foreach ($p as $i => $g) {
            self::assertEqualsWithDelta($g['x'], $c[$i]['x'], 1e-9);
            self::assertEqualsWithDelta($g['y'], $c[$i]['y'], 1e-9);
        }
    }

    public function test_colored_middle_stretches(): void
    {
        $fence = static fn (Node $middle): Box => Math::layout(
            new Fenced('(', ')', new Row([Math::frac('a', 'b'), $middle, Math::frac('c', 'd')])),
            12.0,
            true,
        );
        $plain = $fence(new Middle('|'));
        $colored = $fence(new Color(new Middle('|'), self::RED));

        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);
        self::assertEqualsWithDelta($plain->ascent, $colored->ascent, 1e-9);
        self::assertEqualsWithDelta($plain->descent, $colored->descent, 1e-9);

        // The colored middle is built from the same glyphs at the same places as the plain one, and only those are colored.
        $p = self::glyphs($plain);
        $c = self::glyphs($colored);
        self::assertCount(count($p), $c);
        $red = 0;
        foreach ($p as $i => $g) {
            self::assertSame($g['gid'], $c[$i]['gid']);
            self::assertEqualsWithDelta($g['x'], $c[$i]['x'], 1e-9);
            self::assertEqualsWithDelta($g['y'], $c[$i]['y'], 1e-9);
            $red += $c[$i]['color'] === self::RED ? 1 : 0;
        }
        self::assertGreaterThan(0, $red);
        self::assertLessThan(count($c), $red);
    }

    public function test_colored_cell_aligns(): void
    {
        // A cell that starts with an operator keeps infix spacing, colored or not.
        $minus = static fn (Node $op): Row => new Row([$op, new Number('1')]);
        $rows = static fn (Node $cell): array => [['x', $cell], ['yy', $minus(new Operator('−'))]];
        $plain = Math::layout(Math::aligned($rows($minus(new Operator('−')))), 12.0, true);
        $colored = Math::layout(Math::aligned($rows($minus(new Color(new Operator('−'), self::RED)))), 12.0, true);

        $p = self::glyphs($plain);
        $c = self::glyphs($colored);
        self::assertCount(count($p), $c);
        foreach ($p as $i => $g) {
            self::assertEqualsWithDelta($g['x'], $c[$i]['x'], 1e-9);
            self::assertEqualsWithDelta($g['y'], $c[$i]['y'], 1e-9);
        }
        self::assertContains(self::RED, array_map(static fn (array $g): ?string => $g['color'], $c));
    }

    /** The box of an UnderOver with a colored part, against the same one uncolored: same geometry, colored ink on that part only. */
    private static function assertTinted(UnderOver $plain, UnderOver $colored): void
    {
        $a = Math::layout($plain, 12.0, true);
        $b = Math::layout($colored, 12.0, true);
        self::assertEqualsWithDelta($a->width, $b->width, 1e-9);
        self::assertEqualsWithDelta($a->ascent, $b->ascent, 1e-9);
        self::assertEqualsWithDelta($a->descent, $b->descent, 1e-9);
        $items = static function (Box $box): array {
            $out = [];
            $box->each(static function ($i, float $dx, float $dy) use (&$out): void {
                if ($i instanceof Glyph || $i instanceof Rule) {
                    $out[] = [$i->x + $dx, $i->y + $dy, $i->color];
                }
            });

            return $out;
        };
        $pi = $items($a);
        $ci = $items($b);
        self::assertCount(count($pi), $ci);
        foreach ($pi as $k => $it) {
            self::assertEqualsWithDelta($it[0], $ci[$k][0], 1e-9);
            self::assertEqualsWithDelta($it[1], $ci[$k][1], 1e-9);
        }
        $red = count(array_filter($ci, static fn (array $i): bool => $i[2] === self::RED));
        self::assertGreaterThan(0, $red);
        self::assertLessThan(count($ci), $red);
    }

    public function test_colored_over_brace_is_colored(): void
    {
        $base = new Row([new Identifier('x'), new Identifier('y'), new Identifier('z')]);
        self::assertTinted(
            new UnderOver($base, null, new Operator('⏞')),
            new UnderOver($base, null, new Color(new Operator('⏞'), self::RED)),
        );
    }

    public function test_colored_accent_is_colored(): void
    {
        self::assertTinted(
            new UnderOver(new Identifier('x'), null, new Operator('^'), accent: true),
            new UnderOver(new Identifier('x'), null, new Color(new Operator('^'), self::RED), accent: true),
        );
    }

    public function test_colored_bar_is_colored(): void
    {
        self::assertTinted(
            new UnderOver(new Identifier('x'), null, new Operator('¯'), accent: true),
            new UnderOver(new Identifier('x'), null, new Color(new Operator('¯'), self::RED), accent: true),
        );
    }

    public function test_colored_stretched_arrow_base_is_colored(): void
    {
        $label = new Row([new Identifier('a'), new Identifier('b'), new Identifier('c')]);
        self::assertTinted(
            new UnderOver(new Operator('→'), null, $label),
            new UnderOver(new Color(new Operator('→'), self::RED), null, $label),
        );
    }

    public function test_color_through_a_one_child_row_part(): void
    {
        $xyz = new Row([new Identifier('x'), new Identifier('y'), new Identifier('z')]);
        $red = static fn (string $ch): Color => new Color(new Operator($ch), self::RED);
        self::assertTinted(
            new UnderOver($xyz, null, new Row([new Operator('⏞')])),
            new UnderOver($xyz, null, new Row([$red('⏞')])),
        );
        self::assertTinted(
            new UnderOver(new Row([new Operator('→')]), null, $xyz),
            new UnderOver(new Row([$red('→')]), null, $xyz),
        );
        self::assertTinted(
            new UnderOver(new Identifier('x'), new Row([new Operator('_')]), null, accentUnder: true),
            new UnderOver(new Identifier('x'), new Row([$red('_')]), null, accentUnder: true),
        );
    }

    public function test_innermost_color_wins_on_a_stretched_part(): void
    {
        $xyz = new Row([new Identifier('x'), new Identifier('y'), new Identifier('z')]);
        $blue = '#0000ff';
        $box = Math::layout(new UnderOver($xyz, null, new Color(new Row([new Color(new Operator('⏞'), $blue)]), self::RED)), 12.0, true);
        $colors = [];
        $box->each(static function ($i) use (&$colors): void {
            if ($i instanceof Glyph && $i->color !== null) {
                $colors[$i->color] = true;
            }
        });
        self::assertSame([$blue], array_keys($colors));
    }

    /** @return list<string> glyph colors in drawing order, "-" for none */
    private static function colorsOf(Box $b): array
    {
        return array_map(static fn (array $g): string => $g['color'] ?? '-', self::glyphs($b));
    }

    private static function a(): Identifier
    {
        return new Identifier('a');
    }

    private static function b(): Identifier
    {
        return new Identifier('b');
    }

    public function test_switch_form_keeps_binary_spacing(): void
    {
        $plain = Math::layout(new Row([self::a(), self::plus(), self::b()]), 12.0, true);
        $switch = new TexSource(new Color(new Row([self::plus(), self::b()]), self::RED, true), '\color{red} ', '');
        $colored = Math::layout(new Row([self::a(), $switch]), 12.0, true);

        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);
        self::assertSame(['-', self::RED, self::RED], self::colorsOf($colored));
    }

    public function test_switch_middle_stretches(): void
    {
        $fence = static fn (Node $content): Box => Math::layout(new Fenced('(', ')', $content), 12.0, true);
        $plain = $fence(new Row([Math::frac('a', 'b'), new Middle('|'), self::b()]));
        $colored = $fence(new Row([Math::frac('a', 'b'), new Color(new Row([new Middle('|'), self::b()]), self::RED, true)]));

        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);
        self::assertEqualsWithDelta($plain->ascent, $colored->ascent, 1e-9);
        $p = self::glyphs($plain);
        $c = self::glyphs($colored);
        self::assertCount(count($p), $c);
        foreach ($p as $i => $g) {
            self::assertSame($g['gid'], $c[$i]['gid']);
            self::assertEqualsWithDelta($g['y'], $c[$i]['y'], 1e-9);
        }
        $red = array_filter($c, static fn (array $g): bool => $g['color'] === self::RED);
        self::assertGreaterThan(1, count($red)); // the stretched middle's pieces and b
    }

    public function test_non_inline_row_stays_one_atom(): void
    {
        $atom = Math::layout(new Row([self::a(), new Row([self::plus(), self::b()])]), 12.0, true);
        $colored = Math::layout(new Row([self::a(), new Color(new Row([self::plus(), self::b()]), self::RED)]), 12.0, true);
        $spliced = Math::layout(new Row([self::a(), self::plus(), self::b()]), 12.0, true);

        self::assertEqualsWithDelta($atom->width, $colored->width, 1e-9);
        self::assertNotEquals($spliced->width, $colored->width);
    }

    public function test_nested_inline(): void
    {
        $blue = '#0000ff';
        $inner = new Color(new Row([self::b()]), $blue, true);
        $outer = new Color(new Row([self::plus(), $inner]), self::RED, true);
        $box = Math::layout(new Row([self::a(), $outer]), 12.0, true);
        $plain = Math::layout(new Row([self::a(), self::plus(), self::b()]), 12.0, true);

        self::assertEqualsWithDelta($plain->width, $box->width, 1e-9);
        self::assertSame(['-', self::RED, $blue], self::colorsOf($box));
    }

    public function test_children_are_not_spliced(): void
    {
        $color = new Color(new Row([self::plus(), self::b()]), self::RED, true);
        $row = new Row([self::a(), $color]);

        self::assertSame([self::a()::class, Color::class], array_map(static fn (Node $n): string => $n::class, $row->children()));
        self::assertCount(3, $row->items());
    }

    public function test_operator_color_and_inline_color_on_one_item(): void
    {
        $blue = '#0000ff';
        $inline = new Color(new Row([new Color(self::plus(), $blue), self::b()]), self::RED, true);
        $box = Math::layout(new Row([self::a(), $inline]), 12.0, true);

        self::assertSame(['-', $blue, self::RED], self::colorsOf($box));
    }

    public function test_empty_inline_color_adds_nothing(): void
    {
        $plain = Math::layout(new Row([self::a()]), 12.0, true);
        $empty = Math::layout(new Row([self::a(), new Color(new Row([]), self::RED, true)]), 12.0, true);

        self::assertEqualsWithDelta($plain->width, $empty->width, 1e-9);
        self::assertSame($plain->simple, $empty->simple);
    }
}
