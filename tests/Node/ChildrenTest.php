<?php

declare(strict_types=1);

namespace Phactorial\Tests\Node;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Node\Color;
use Phactorial\Node\DisplayStyle;
use Phactorial\Node\Fallback;
use Phactorial\Node\Fenced;
use Phactorial\Node\Fraction;
use Phactorial\Node\Grid;
use Phactorial\Node\Node;
use Phactorial\Node\Phantom;
use Phactorial\Node\Root;
use Phactorial\Node\Row;
use Phactorial\Node\Scripts;
use Phactorial\Node\TexSource;
use Phactorial\Node\UnderOver;
use Phactorial\Render\TextWriter;
use Phactorial\Tex\TexReader;

/** Node::children() is public API: every child, in the order a reader of the formula meets them. */
final class ChildrenTest extends TestCase
{
    /** @return iterable<string, array{Node, class-string<Node>, list<string>}> */
    public static function nodes(): iterable
    {
        yield 'row' => [Math::row('a', 'b'), Row::class, ['a', 'b']];
        yield 'scripts' => [Math::sup('x', '2'), Scripts::class, ['x', '2']];
        yield 'over' => [Math::over('x', 'a'), UnderOver::class, ['x', 'a']];
        yield 'fraction' => [Math::frac('a', 'b'), Fraction::class, ['a', 'b']];
        // Prescripts (subscript, then superscript), the base, then the scripts (subscript, then superscript).
        yield 'prescripts' => [Math::prescripts('X', 'p', 'q', 's', 't'), Scripts::class, ['p', 'q', 'X', 's', 't']];
        yield 'under and over' => [Math::underOver('B', 'u', 'o'), UnderOver::class, ['B', 'u', 'o']];
        yield 'root' => [Math::root('r', 'i'), Root::class, ['r', 'i']];
        yield 'fenced' => [Math::fenced('(', ')', 'a'), Fenced::class, ['a']];
        yield 'matrix, row by row' => [Math::matrix([['a', 'b'], ['c', 'd']], 'none'), Grid::class, ['a', 'b', 'c', 'd']];
        yield 'phantom' => [TexReader::read('\phantom{x}')->node, Phantom::class, ['x']];
        yield 'display style' => [TexReader::read('\displaystyle x')->node, DisplayStyle::class, ['x']];
        yield 'color' => [new Color(Math::row('x'), '#ff0000'), Color::class, ['x']];
        yield 'tex source' => [TexReader::read('\boxed{x}')->node, TexSource::class, ['x']];
        yield 'fallback' => [TexReader::read('\boxed{x}')->node->children()[0], Fallback::class, ['x']];
    }

    /**
     * @param class-string<Node> $type
     * @param list<string> $expected
     */
    #[DataProvider('nodes')]
    public function test_children_visits_every_child_in_reading_order(Node $node, string $type, array $expected): void
    {
        self::assertInstanceOf($type, $node);
        self::assertSame($expected, array_map(static fn (Node $child): string => TextWriter::write($child), $node->children()));
    }

    public function test_a_leaf_has_no_children(): void
    {
        self::assertSame([], TexReader::read('1')->node->children());
    }
}
