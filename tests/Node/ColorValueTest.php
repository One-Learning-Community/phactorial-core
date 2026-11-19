<?php

declare(strict_types=1);

namespace Phactorial\Tests\Node;

use Phactorial\Layout\Glyph;
use Phactorial\Layout\Rule;
use Phactorial\Node\Color;
use Phactorial\Node\Identifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A color is always #rrggbb in lowercase hex, whoever builds the node or the item. */
final class ColorValueTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function bad(): iterable
    {
        foreach (['red', '#FF0000', '#f00', '#ff00', 'red" onload="x', 'url(#a)', ''] as $v) {
            yield $v === '' ? 'empty' : $v => [$v];
        }
    }

    #[DataProvider('bad')]
    public function test_a_color_node_rejects_a_non_hex_value(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Color(new Identifier('x'), $value);
    }

    #[DataProvider('bad')]
    public function test_a_glyph_rejects_a_non_hex_color(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Glyph(1, 'x', 0.0, 0.0, 12.0))->withColor($value);
    }

    #[DataProvider('bad')]
    public function test_a_rule_rejects_a_non_hex_color(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Rule(0.0, 0.0, 1.0, 1.0))->withColor($value);
    }

    public function test_a_hex_color_is_accepted(): void
    {
        self::assertSame('#ff0000', (new Color(new Identifier('x'), '#ff0000'))->color);
        self::assertSame('#00ff00', (new Glyph(1, 'x', 0.0, 0.0, 12.0))->withColor('#00ff00')->color);
        self::assertSame('#0000ff', (new Rule(0.0, 0.0, 1.0, 1.0))->withColor('#0000ff')->color);
    }
}
