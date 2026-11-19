<?php

declare(strict_types=1);

namespace Phactorial\Tests\Mathml;

use Phactorial\Layout\Box;
use Phactorial\Layout\Glyph;
use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Color;
use Phactorial\Node\Fallback;
use Phactorial\Node\Fenced;
use Phactorial\Node\Identifier;
use Phactorial\Node\Middle;
use Phactorial\Node\Node;
use Phactorial\Node\Row;
use Phactorial\NoteLevel;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TexWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** mathcolor reads into Color nodes and writes back as mstyle. */
final class MathmlColorTest extends TestCase
{
    private static function read(string $inner): Node
    {
        return MathmlReader::read('<math>' . $inner . '</math>')->node;
    }

    /** @return list<array{int, ?string, float}> glyph ids, fills and x offsets, in drawing order, and the width last */
    private static function fingerprint(Node $node): array
    {
        $box = Math::layout($node);
        $out = [];
        $box->each(static function ($item, float $dx) use (&$out): void {
            if ($item instanceof Glyph) {
                $out[] = [$item->gid, $item->color, round($dx + $item->x, 3)];
            }
        });
        $out[] = [-1, null, round($box->width, 3)];

        return $out;
    }

    public function test_mathcolor_on_an_identifier(): void
    {
        $node = self::read('<mi mathcolor="red">x</mi>');
        self::assertInstanceOf(Color::class, $node);
        self::assertSame('#ff0000', $node->color);
        self::assertFalse($node->inline);
        self::assertEquals(new Identifier('x'), $node->content);
    }

    public function test_mathcolor_on_an_mrow_and_an_mstyle(): void
    {
        $row = self::read('<mrow mathcolor="#0F0"><mi>a</mi><mi>b</mi></mrow>');
        self::assertInstanceOf(Color::class, $row);
        self::assertSame('#00ff00', $row->color);
        self::assertInstanceOf(Row::class, $row->content);

        $style = self::read('<mstyle mathcolor="Blue"><mi>a</mi></mstyle>');
        self::assertInstanceOf(Color::class, $style);
        self::assertSame('#0000ff', $style->color);
    }

    public function test_the_innermost_color_wins(): void
    {
        $node = self::read('<mstyle mathcolor="red"><mi>a</mi><mi mathcolor="blue">b</mi></mstyle>');
        self::assertInstanceOf(Color::class, $node);
        self::assertSame('#ff0000', $node->color);
        self::assertInstanceOf(Row::class, $node->content);
        $inner = $node->content->children[1];
        self::assertInstanceOf(Color::class, $inner);
        self::assertSame('#0000ff', $inner->color);

        $fills = array_values(array_unique(array_filter(array_column(self::fingerprint($node), 1))));
        self::assertSame(['#ff0000', '#0000ff'], $fills);
    }

    public function test_a_colored_delimiter_still_pairs_and_the_drop_is_noted_once(): void
    {
        $r = MathmlReader::read('<math><mo mathcolor="red">(</mo><mi>a</mi><mo>)</mo><mo>[</mo><mi>b</mi><mo mathcolor="blue">]</mo></math>');
        self::assertInstanceOf(Row::class, $r->node);
        $kids = array_map(static fn (Node $n): Node => Fallback::unwrap($n), $r->node->children);
        self::assertContainsOnlyInstancesOf(Fenced::class, $kids);
        self::assertCount(2, $kids);
        $notes = $r->notes();
        self::assertCount(1, $notes);
        self::assertSame(NoteLevel::Approximated, $notes[0]->level);
        self::assertSame('color on a paired delimiter is not drawn', $notes[0]->reason);
    }

    public function test_a_colored_bar_pair_is_noted(): void
    {
        $r = MathmlReader::read('<math><mo mathcolor="red">|</mo><mi>a</mi><mo>|</mo></math>');
        self::assertInstanceOf(Fenced::class, Fallback::unwrap($r->node));
        self::assertSame(['color on a paired delimiter is not drawn'], array_map(static fn ($n): string => $n->reason, $r->notes()));
    }

    public function test_uncolored_pairs_have_no_note(): void
    {
        self::assertSame([], MathmlReader::read('<math><mo>(</mo><mi>a</mi><mo>)</mo></math>')->notes());
    }

    public function test_writer_output_for_a_non_inline_color(): void
    {
        $xml = MathmlWriter::write(Math::mathml('<math><mi mathcolor="red">x</mi></math>'));
        self::assertSame('<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><mstyle mathcolor="#ff0000"><mi>x</mi></mstyle></math>', $xml);
    }

    public function test_writer_output_for_an_inline_color_wraps_each_item_in_place(): void
    {
        $xml = MathmlWriter::write(Math::tex('a \color{red} b + c'));
        self::assertSame(
            '<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><mrow><mi>a</mi><mstyle mathcolor="#ff0000"><mi>b</mi></mstyle><mstyle mathcolor="#ff0000"><mo>+</mo></mstyle><mstyle mathcolor="#ff0000"><mi>c</mi></mstyle></mrow></math>',
            $xml,
        );
    }

    public function test_a_braced_color_group_is_one_colored_row(): void
    {
        $xml = MathmlWriter::write(Math::tex('a {\color{red} b + c} d'));
        self::assertSame(
            '<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><mrow><mi>a</mi><mstyle mathcolor="#ff0000"><mrow><mi>b</mi><mo>+</mo><mi>c</mi></mrow></mstyle><mi>d</mi></mrow></math>',
            $xml,
        );
    }

    public function test_a_colored_operator_in_a_row_keeps_its_color(): void
    {
        $xml = MathmlWriter::write(Math::mathml('<math><mi>a</mi><mo mathcolor="red">+</mo><mi>b</mi></math>'));
        self::assertStringContainsString('<mstyle mathcolor="#ff0000"><mo>+</mo></mstyle>', $xml);
        $xml = MathmlWriter::write(Math::tex('a \textcolor{red}{+} b'));
        self::assertStringContainsString('<mstyle mathcolor="#ff0000"><mo>+</mo></mstyle>', $xml);
    }

    public function test_the_operators_own_color_beats_the_inline_one(): void
    {
        $xml = MathmlWriter::write(Math::tex('{\color{red} a \textcolor{blue}{+} b}'));
        self::assertStringContainsString('<mstyle mathcolor="#0000ff"><mo>+</mo></mstyle>', $xml);
        self::assertStringNotContainsString('<mstyle mathcolor="#ff0000"><mstyle', $xml);
    }

    public function test_a_colored_limit_operator_keeps_its_color(): void
    {
        $xml = MathmlWriter::write(Math::tex('\textcolor{red}{\sum}_{i}'));
        self::assertSame('<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><munder><mstyle mathcolor="#ff0000"><mo form="prefix">∑</mo></mstyle><mi>i</mi></munder></math>', $xml);
    }

    public function test_a_middle_inside_an_inline_color_stretches_in_mathml(): void
    {
        // A tree built by hand, as a reader other than TeX's could make it.
        $tree = new Fenced('(', ')', new Row([
            new Identifier('a'),
            new Color(new Row([new Middle('|'), new Identifier('b')]), '#ff0000', true),
        ]));
        $xml = MathmlWriter::write($tree);
        self::assertStringContainsString('<mo mathcolor="#ff0000" stretchy="true" lspace="0.1667em" rspace="0.1667em">|</mo><mstyle mathcolor="#ff0000"><mi>b</mi></mstyle>', $xml);
        $stretched = Fenced::stretchMiddles($tree->content, 10.0);
        self::assertInstanceOf(Row::class, $stretched);
        self::assertInstanceOf(Color::class, $stretched->children[1]);
        self::assertTrue($stretched->children[1]->inline);
        self::assertSame('#ff0000', $stretched->children[1]->color);

        // From TeX the switch runs past \middle, so the Middle is spliced and stays stretchy.
        $xml = MathmlWriter::write(Math::tex('\left( a \color{red} \middle| b \right)'));
        self::assertStringContainsString('<mo mathcolor="#ff0000" stretchy="true" lspace="0.1667em" rspace="0.1667em">|</mo>', $xml);
    }

    public function test_the_dropped_color_note_takes_its_path_from_the_color(): void
    {
        $r = MathmlReader::read('<math><mi>x</mi><mstyle mathcolor="bogus"><mo mathcolor="red">(</mo><mi>a</mi><mo>)</mo></mstyle></math>');
        $where = array_values(array_map(static fn ($n): string => $n->where, array_filter($r->notes(), static fn ($n): bool => $n->reason === 'color on a paired delimiter is not drawn')));
        self::assertSame(['math > mstyle[1] > mo[0]'], $where);
    }

    public function test_round_trip_through_mathml_back_to_tex(): void
    {
        $xml = MathmlWriter::write(Math::tex('a \textcolor{red}{b}'));
        self::assertSame('a \textcolor{#ff0000}{b}', TexWriter::write(Math::mathml($xml)));
        self::assertSame(self::fingerprint(Math::tex('a \textcolor{red}{b}')), self::fingerprint(Math::mathml($xml)));
    }

    public function test_round_trip_of_an_inline_color_keeps_width_and_colors(): void
    {
        $tex = Math::tex('a {\color{red} + b}');
        $back = Math::mathml(MathmlWriter::write($tex));
        self::assertSame(self::fingerprint($tex), self::fingerprint($back));
        self::assertContains('#ff0000', array_column(self::fingerprint($back), 1));
    }

    /** @return iterable<string, array{string}> */
    public static function hostile(): iterable
    {
        yield 'markup' => ['red"><script>alert(1)</script>'];
        yield 'angle' => ['<b>'];
        yield 'quote' => ['re"d'];
        yield 'apostrophe' => ["re'd"];
        yield 'ampersand' => ['a&amp;b'];
        yield 'function' => ['url(x)'];
        yield 'bad hex' => ['#ff00zz'];
        yield 'newline' => ["red\nblue"];
        yield 'multibyte' => ["r\u{e9}d"];
    }

    #[DataProvider('hostile')]
    public function test_hostile_values_are_rejected_and_never_written(string $value): void
    {
        $xml = '<math><mi mathcolor="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . '">x</mi></math>';
        $r = MathmlReader::read($xml);
        self::assertNull(Fallback::color($r->node));
        $notes = $r->notes();
        self::assertCount(1, $notes);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9#.,!:\- ?`;]*$/', $notes[0]->reason);
        $out = MathmlWriter::write($r->node);
        self::assertSame('<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><mi>x</mi></math>', $out);
    }

    public function test_a_rejected_value_is_noted_once_per_distinct_value(): void
    {
        $r = MathmlReader::read('<math><mi mathcolor="nope">a</mi><mi mathcolor="nope">b</mi><mi mathcolor="nada">c</mi></math>');
        self::assertCount(2, $r->notes());
        self::assertSame('unknown color `nope`; drawn in the default color', $r->notes()[0]->reason);
    }

    public function test_an_empty_mstyle_with_a_color_is_zero_width(): void
    {
        $node = Math::mathml('<math><mstyle mathcolor="red"/></math>');
        self::assertSame(0.0, Math::layout($node)->width);
        self::assertStringContainsString('mathcolor="#ff0000"', MathmlWriter::write($node));
    }

    public function test_a_rejected_color_on_a_delimiter_still_pairs_and_keeps_its_note(): void
    {
        $group = '<mo mathcolor="bogus">(</mo><mi>a</mi><mo>)</mo>';
        $r = MathmlReader::read('<math>' . $group . $group . '</math>');
        self::assertInstanceOf(Row::class, $r->node);
        self::assertCount(2, $r->node->children);
        foreach ($r->node->children as $child) {
            self::assertInstanceOf(Fenced::class, Fallback::unwrap($child));
        }
        self::assertSame(['unknown color `bogus`; drawn in the default color'], array_map(static fn ($n): string => $n->reason, $r->notes()));
        self::assertSame(
            self::fingerprint(MathmlReader::read('<math><mo>(</mo><mi>a</mi><mo>)</mo><mo>(</mo><mi>a</mi><mo>)</mo></math>')->node),
            self::fingerprint($r->node),
        );

        $bars = MathmlReader::read('<math><mo mathcolor="bogus">|</mo><mi>a</mi><mo mathcolor="bogus">|</mo></math>');
        self::assertInstanceOf(Fenced::class, Fallback::unwrap($bars->node));
    }

    public function test_the_dropped_color_note_carries_the_path(): void
    {
        $r = MathmlReader::read('<math><mi>x</mi><mo mathcolor="red">(</mo><mi>a</mi><mo>)</mo></math>');
        self::assertSame(['math > mo[1]'], array_map(static fn ($n): string => $n->where, $r->notes()));
    }

    public function test_a_single_spliced_item_keeps_its_inline_color(): void
    {
        $xml = MathmlWriter::write(new Row([new Color(new Row([new Identifier('a')]), '#ff0000', true)]));
        self::assertSame('<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><mstyle mathcolor="#ff0000"><mi>a</mi></mstyle></math>', $xml);
    }

    public function test_a_color_of_the_wrong_shape_is_never_written(): void
    {
        $color = (new \ReflectionClass(Color::class))->newInstanceWithoutConstructor();
        foreach (['color' => 'red"><script>', 'content' => new Identifier('x'), 'inline' => false] as $prop => $value) {
            (new \ReflectionProperty(Color::class, $prop))->setValue($color, $value);
        }
        self::assertSame('<math xmlns="http://www.w3.org/1998/Math/MathML" display="block"><mi>x</mi></math>', MathmlWriter::write($color));
    }

    /** @return list<?string> the fill of each glyph, in drawing order */
    private static function fills(string $inner): array
    {
        return array_column(array_filter(self::fingerprint(self::read($inner)), static fn (array $g): bool => $g[0] >= 0), 1);
    }

    public function test_mathcolor_on_a_table_cell_and_row_is_drawn(): void
    {
        self::assertSame([null, '#ff0000'], self::fills('<mtable><mtr><mtd><mi>a</mi></mtd><mtd mathcolor="red"><mi>b</mi></mtd></mtr></mtable>'));
        self::assertSame(['#0000ff', '#0000ff'], self::fills('<mtable><mtr mathcolor="blue"><mtd><mi>a</mi></mtd><mtd><mi>b</mi></mtd></mtr></mtable>'));
        // The cell is inside the row, so the cell's color wins.
        self::assertSame(['#0000ff', '#ff0000'], self::fills('<mtable><mtr mathcolor="blue"><mtd><mi>a</mi></mtd><mtd mathcolor="red"><mi>b</mi></mtd></mtr></mtable>'));
    }

    public function test_a_rejected_table_color_gives_the_note(): void
    {
        foreach (['<mtr mathcolor="nope"><mtd><mi>a</mi></mtd></mtr>', '<mtr><mtd mathcolor="nope"><mi>a</mi></mtd></mtr>'] as $rows) {
            $notes = MathmlReader::read('<math><mtable>' . $rows . '</mtable></math>')->notes();
            self::assertCount(1, $notes, $rows);
            self::assertStringStartsWith('unknown color `nope`', $notes[0]->reason);
        }
    }
}
