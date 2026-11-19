<?php

declare(strict_types=1);

namespace Phactorial\Tests\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Identifier;
use Phactorial\Node\Node;
use Phactorial\Node\Row;
use Phactorial\Node\Scripts;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexSymbols;
use Phactorial\Tokenizer;

final class FormSpacingTexTest extends TestCase
{
    private const float TOLERANCE = 0.05;

    private static function width(Node $n, bool $display): float
    {
        return Math::layout($n, 18.0, $display)->width;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inputs(): iterable
    {
        yield 'function name' => ['<math><mo>sin</mo><mi>x</mi></math>'];
        yield 'partial' => ['<math><mo>∂</mo><mi>x</mi></math>'];
        yield 'infix name' => ['<math><mi>a</mi><mo>mod</mo><mi>b</mi></math>'];
        yield 'fenced letter' => ['<math><mrow><mo>(</mo><mo>x</mo><mo>)</mo></mrow></math>'];
        yield 'lone letter' => ['<math><mo>x</mo></math>'];
        yield 'colored name' => ['<math><mstyle mathcolor="red"><mo>sin</mo></mstyle><mi>x</mi></math>'];
        yield 'colored letter' => ['<math><mstyle mathcolor="red"><mo>x</mo></mstyle><mi>x</mi></math>'];
        yield 'limit name' => ['<math><mo>lim</mo><mi>x</mi></math>'];
        yield 'max name' => ['<math><mo>max</mo><mi>x</mi></math>'];
        yield 'det name' => ['<math><mo>det</mo><mi>A</mi></math>'];
        yield 'limit name after an operand' => ['<math><mi>a</mi><mo>lim</mo></math>'];
        yield 'limit name between operands' => ['<math><mi>a</mi><mo>lim</mo><mi>b</mi></math>'];
        yield 'name between operands' => ['<math><mi>a</mi><mo>sin</mo><mi>b</mi></math>'];
        yield 'name before a fence' => ['<math><mo>sin</mo><mrow><mo>(</mo><mi>x</mi><mo>)</mo></mrow></math>'];
        yield 'name before a relation' => ['<math><mo>sin</mo><mo>=</mo><mi>x</mi></math>'];
        yield 'name with a superscript' => ['<math><msup><mo>sin</mo><mn>2</mn></msup><mi>x</mi></math>'];
        yield 'partial with a superscript' => ['<math><msup><mo>∂</mo><mn>2</mn></msup><mi>x</mi></math>'];
        yield 'infinity with a superscript' => ['<math><msup><mo>∞</mo><mn>2</mn></msup></math>'];
        yield 'fractional kern' => ['<math><mi>a</mi><mo lspace="0.1em" rspace="0.1em">x</mo><mi>b</mi></math>'];
        yield 'colored partial with a superscript' => ['<math><msup><mstyle mathcolor="red"><mo>∂</mo></mstyle><mn>2</mn></msup><mi>x</mi></math>'];
        yield 'name subscript' => ['<math><msub><mi>a</mi><mo>sin</mo></msub></math>'];
        yield 'names in a subscript' => ['<math><msub><mi>a</mi><mrow><mi>b</mi><mo>mod</mo><mi>c</mi></mrow></msub></math>'];
        yield 'letter in a subscript' => ['<math><msub><mi>a</mi><mrow><mi>b</mi><mo>x</mo><mi>c</mi></mrow></msub></math>'];
        yield 'name in a root index' => ['<math><mroot><mi>a</mi><mo>sin</mo></mroot></math>'];
        yield 'name in a limit argument' => ['<math><munder><mo>lim</mo><msqrt><mo>sin</mo></msqrt></munder><mi>f</mi></math>'];
        yield 'letter in a limit argument' => ['<math><munder><mo>lim</mo><mo>x</mo></munder><mi>f</mi></math>'];
        yield 'accent over a letter operator' => ['<math><mover><mo>x</mo><mo>^</mo></mover></math>'];
        yield 'accent over a letter operator in a row' => ['<math><mi>a</mi><mover><mo>x</mo><mo>^</mo></mover><mi>b</mi></math>'];
        yield 'accent over a relation' => ['<math><mover><mo>=</mo><mo>^</mo></mover></math>'];
        yield 'accent over a name' => ['<math><mover><mo>sin</mo><mo>^</mo></mover></math>'];
        yield 'colored accent over a letter operator' => ['<math><mstyle mathcolor="red"><mover><mo>x</mo><mo>^</mo></mover></mstyle></math>'];
        yield 'fenced letter over a plus' => ['<math><mi>a</mi><mover><mo>+</mo><mrow><mo>(</mo><mo>x</mo><mo>)</mo></mrow></mover><mi>b</mi></math>'];
    }

    #[DataProvider('inputs')]
    public function test_width_survives_tex(string $mathml): void
    {
        $node = MathmlReader::read($mathml)->node;
        $tex = TexWriter::write($node);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($node, $display), self::width(Math::tex($tex), $display), self::TOLERANCE, $tex);
        }
    }

    #[DataProvider('inputs')]
    public function test_writing_is_stable(string $mathml): void
    {
        $tex = TexWriter::write(MathmlReader::read($mathml)->node);
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
    }

    public function test_kerns_are_spelled_in_mu_in_a_script_and_stay_stable(): void
    {
        foreach (['x_{a\\mkern9mu b}' => 'x_{a \\mkern9mu b}', 'x_{a\\mkern1.8mu b}' => 'x_{a \\mkern1.8mu b}', 'a \\hspace{-0.5em} b' => 'a \\hspace{-0.5em} b'] as $src => $want) {
            $tex = TexWriter::write(Math::tex($src));
            self::assertSame($want, $tex);
            self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        }
    }

    public function test_a_node_writes_the_same_alone_and_after_its_parent(): void
    {
        $sub = MathmlReader::read('<math><mi>b</mi><mo>x</mo><mi>c</mi></math>')->node;
        $before = TexWriter::write($sub);
        TexWriter::write(new Scripts(new Identifier('a'), $sub, null));
        self::assertSame($before, TexWriter::write($sub));
    }

    public function test_a_node_shared_by_a_subscript_and_the_top_level_keeps_its_width(): void
    {
        $shared = MathmlReader::read('<math><mi>b</mi><mo>x</mo><mi>c</mi></math>')->node;
        $tree = new Row([new Scripts(new Identifier('a'), $shared, null), $shared]);
        $back = Math::tex(TexWriter::write($tree));
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($tree, $display), self::width($back, $display), self::TOLERANCE);
        }
    }

    public function test_an_accent_over_a_tex_base_writes_without_kerns(): void
    {
        foreach (['\\widehat{x}', '\\hat{x}', '\\widehat{=}'] as $tex) {
            self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        }
    }

    public function test_an_accent_over_an_operator_writes_its_spacing_around_the_stack(): void
    {
        $tex = TexWriter::write(MathmlReader::read('<math><mover><mo>x</mo><mo>^</mo></mover></math>')->node);
        self::assertSame('\\: \\widehat{\\mathop{x}} \\:', $tex);
    }

    public function test_an_accented_operator_in_a_script_keeps_its_kerns_beside_it(): void
    {
        // In a script, a leading negative kern moves after the symbol only at the start of the script argument; mid-row both
        // kerns stay beside the accent stack, whose neighbors decide that.
        $tree = MathmlReader::read('<math><msub><mi>c</mi><mrow><mi>a</mi><mover><mo>x</mo><mo>^</mo></mover><mi>b</mi></mrow></msub></math>')->node;
        $tex = TexWriter::write($tree);
        self::assertSame('c_{a \\mkern-1mu \\widehat{\\mathop{x}} \\mkern-1mu b}', $tex);
        $back = Math::tex($tex);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($tree, $display), self::width($back, $display), self::TOLERANCE);
        }
    }

    public function test_a_name_the_reader_spaces_alike_is_written_plain(): void
    {
        self::assertSame('\lim x', TexWriter::write(MathmlReader::read('<math><mo>lim</mo><mi>x</mi></math>')->node));
        self::assertSame('\max x', TexWriter::write(MathmlReader::read('<math><mo>max</mo><mi>x</mi></math>')->node));
        self::assertSame('\sin x', TexWriter::write(MathmlReader::read('<math><mi>sin</mi><mo>&#x2061;</mo><mi>x</mi></math>')->node));
    }

    /** Every row of three from these keeps its width: what a name, a symbol or a letter reads back with depends on its neighbors. */
    public function test_neighbors_do_not_change_the_width(): void
    {
        $pieces = ['<mi>a</mi>', '<mo>lim</mo>', '<mo>sin</mo>', '<mo>(</mo>', '<mo>)</mo>', '<mo>=</mo>', '<mo>+</mo>', '<mo>∂</mo>', '<mo>x</mo>', '<mo>,</mo>', '<mfrac><mi>a</mi><mi>b</mi></mfrac>', '<mo>mod</mo>'];
        foreach ($pieces as $a) {
            foreach ($pieces as $b) {
                foreach ($pieces as $c) {
                    $node = MathmlReader::read('<math>' . $a . $b . $c . '</math>')->node;
                    $tex = TexWriter::write($node);
                    $back = Math::tex($tex);
                    foreach ([true, false] as $display) {
                        self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $a . $b . $c . ' as ' . $tex);
                    }
                }
            }
        }
    }

    public function test_color_switch_keeps_its_width(): void
    {
        foreach (['{\color{red} \sin} x', 'a {\color{red} \partial} x'] as $tex) {
            $node = Math::tex($tex);
            $back = Math::tex(TexWriter::write($node));
            foreach ([true, false] as $display) {
                self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $tex);
            }
        }
    }

    public function test_the_identifier_list_matches_the_reader(): void
    {
        $list = (new \ReflectionClassConstant(TexWriter::class, 'READ_AS_IDENTIFIER'))->getValue();
        $read = [];
        foreach (TexSymbols::ORD as $char) {
            if (Tokenizer::tokenize($char)[0] instanceof Identifier) {
                $read[] = $char;
            }
        }
        sort($list);
        $read = array_values(array_unique($read));
        sort($read);
        self::assertSame($read, $list);
    }

    public function test_unchanged_spellings(): void
    {
        self::assertSame('\sin x', TexWriter::write(Math::tex('\sin x')));
        self::assertSame('\partial x', TexWriter::write(Math::tex('\partial x')));
        self::assertSame('a \bmod b', TexWriter::write(Math::tex('a \bmod b')));
        self::assertSame('\left( x \right)', TexWriter::write(Math::tex('\left( x \right)')));
        self::assertSame('a \mathop{x} b', TexWriter::write(Math::tex('a \mathop{x} b')));
        self::assertSame(
            'a \: \mathop{x} \: b',
            TexWriter::write(MathmlReader::read('<math><mi>a</mi><mo>x</mo><mi>b</mi></math>')->node),
        );
    }
}
