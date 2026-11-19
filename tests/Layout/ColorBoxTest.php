<?php

declare(strict_types=1);

namespace Phactorial\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Phactorial\Layout\DisplayItem;
use Phactorial\Layout\Glyph;
use Phactorial\Layout\Rule;
use Phactorial\Math;
use Phactorial\Node\Color;
use Phactorial\Node\Scripts;

final class ColorBoxTest extends TestCase
{
    public function test_items_carry_the_color(): void
    {
        $box = Math::layout(new Color(Math::row('a+b'), '#ff0000'));

        $viaEach = [];
        $box->each(static function ($item) use (&$viaEach): void {
            self::assertTrue($item instanceof Glyph || $item instanceof Rule);
            $viaEach[] = $item->color;
        });

        self::assertNotEmpty($viaEach);
        self::assertSame(array_fill(0, count($viaEach), '#ff0000'), $viaEach);
        self::assertNotEmpty($box->items);
        foreach ($box->items as $item) {
            self::assertTrue($item instanceof Glyph || $item instanceof Rule);
            self::assertSame('#ff0000', $item->color);
        }
    }

    public function test_uncolored_items_stay_null(): void
    {
        $box = Math::layout(Math::row('a+b'));

        self::assertNotEmpty($box->items);
        foreach ($box->items as $item) {
            self::assertTrue($item instanceof Glyph || $item instanceof Rule);
            self::assertNull($item->color);
        }
    }

    public function test_innermost_wins(): void
    {
        $node = new Color(Math::row('a', new Color(Math::row('b'), '#0000ff'), 'c'), '#ff0000');
        $colors = [];
        foreach (Math::layout($node)->items as $item) {
            self::assertInstanceOf(Glyph::class, $item);
            $colors[$item->text] = $item->color;
        }

        self::assertCount(3, $colors);
        self::assertSame(['#ff0000', '#0000ff', '#ff0000'], array_values($colors));
    }

    public function test_metrics_match(): void
    {
        $content = Math::row('f', Math::sup('x', '2'));
        $plain = Math::layout($content);
        $colored = Math::layout(new Color($content, '#ff0000'));

        self::assertSame($plain->width, $colored->width);
        self::assertSame($plain->ascent, $colored->ascent);
        self::assertSame($plain->descent, $colored->descent);
        self::assertSame($plain->italic, $colored->italic);
        self::assertSame($plain->simple, $colored->simple);
        self::assertSame($plain->trail, $colored->trail);
        self::assertSame($plain->firstGid, $colored->firstGid);
        self::assertSame($plain->lastGid, $colored->lastGid);
        self::assertSame($plain->lastAscent, $colored->lastAscent);
        self::assertSame($plain->lastDescent, $colored->lastDescent);
    }

    public function test_metrics_match_for_a_single_letter(): void
    {
        $content = Math::row('f');
        $plain = Math::layout($content);
        $colored = Math::layout(new Color($content, '#ff0000'));

        self::assertTrue($plain->simple);
        self::assertGreaterThan(0.0, $plain->italic);
        self::assertTrue($colored->simple);
        self::assertSame($plain->italic, $colored->italic);
        self::assertSame($plain->width, $colored->width);
    }

    public function test_script_on_colored_base(): void
    {
        $plain = Math::layout(new Scripts(Math::row('x'), null, Math::row('2')));
        $colored = Math::layout(new Scripts(new Color(Math::row('x'), '#ff0000'), null, Math::row('2')));

        $find = static function ($box): Glyph {
            foreach ($box->items as $item) {
                if ($item instanceof Glyph && str_contains((string) $item->text, '2')) {
                    return $item;
                }
            }
            self::fail('no superscript glyph');
        };
        $a = $find($plain);
        $b = $find($colored);

        self::assertSame($a->x, $b->x);
        self::assertSame($a->y, $b->y);
        self::assertNull($b->color);
    }

    public function test_rules_colored(): void
    {
        $rules = array_values(array_filter(
            Math::layout(new Color(Math::frac('a', 'b'), '#00ff00'))->items,
            static fn ($i): bool => $i instanceof Rule,
        ));

        self::assertCount(1, $rules);
        self::assertSame('#00ff00', $rules[0]->color);
    }

    public function test_with_color(): void
    {
        $glyph = new Glyph(1, 'a', 1.0, 2.0, 12.0);
        $rule = new Rule(1.0, 2.0, 3.0, 4.0);

        self::assertSame('#123456', $glyph->withColor('#123456')->color);
        self::assertNull($glyph->color);
        self::assertSame('#123456', $rule->withColor('#123456')->color);
        self::assertNull($rule->color);
        self::assertSame('#123456', $glyph->withColor('#123456')->translated(1.0, 1.0)->color);
        self::assertSame('#123456', $rule->withColor('#123456')->translated(1.0, 1.0)->color);
    }

    public function test_a_leaf_color_beats_the_box_color(): void
    {
        $inner = new \Phactorial\Layout\Box();
        $inner->add((new Glyph(1, 'a', 0.0, 0.0, 12.0))->withColor('#0000ff'));
        $inner->add(new Glyph(2, 'b', 5.0, 0.0, 12.0));
        $items = Color::box($inner, '#ff0000')->items;

        self::assertInstanceOf(Glyph::class, $items[0]);
        self::assertInstanceOf(Glyph::class, $items[1]);
        self::assertSame('#0000ff', $items[0]->color);
        self::assertSame('#ff0000', $items[1]->color);
    }

    public function test_items_without_a_color_are_passed_through(): void
    {
        $inner = new \Phactorial\Layout\Box();
        $inner->add(new CountedItem(1.0, 2.0));
        $box = new \Phactorial\Layout\Box();
        $box->place($inner, 0.0, 0.0);
        $colored = Color::box($box, '#ff0000');

        self::assertInstanceOf(CountedItem::class, $colored->items[0]);
    }

    public function test_each_colored_passes_the_resolved_color_without_copying_items(): void
    {
        $box = Math::layout(Math::row('a', new Color(Math::frac('b', new Color(Math::row('c'), '#0000ff')), '#ff0000'), '+d'));

        $copied = [];
        $box->each(static function (DisplayItem $item, float $dx, float $dy) use (&$copied): void {
            $copied[] = [$item instanceof Glyph || $item instanceof Rule ? $item->color : null, round($dx, 6), round($dy, 6)];
        });
        $resolved = [];
        $box->eachColored(static function (DisplayItem $item, float $dx, float $dy, ?string $color) use (&$resolved): void {
            // The item is as laid out: it carries no color of a box above it.
            $resolved[] = [$color, round($dx, 6), round($dy, 6)];
        });

        self::assertSame($copied, $resolved);
        self::assertContains('#ff0000', array_column($resolved, 0));
        self::assertContains('#0000ff', array_column($resolved, 0));
        self::assertContains(null, array_column($resolved, 0));
        $box->eachColored(static function (DisplayItem $item, float $dx, float $dy, ?string $color): void {
            if ($color !== null && ($item instanceof Glyph || $item instanceof Rule)) {
                self::assertTrue($item->color === null || $item->color === $color);
            }
        });
    }
}
