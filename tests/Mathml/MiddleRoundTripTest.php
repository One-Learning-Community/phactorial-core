<?php

declare(strict_types=1);

namespace Phactorial\Tests\Mathml;

use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Color;
use Phactorial\Node\Fenced;
use Phactorial\Node\Identifier;
use Phactorial\Node\Middle;
use Phactorial\Node\Row;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TextWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A `\middle` written to MathML reads back as a `\middle`, with its color and its own fence. */
final class MiddleRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formulas(): iterable
    {
        yield 'bar' => ['\left( a \middle| b \right)'];
        yield 'double bar' => ['\left( a \middle\| b \right)'];
        yield 'nested' => ['\left( a \middle| \left[ b \middle| c \right] \right)'];
        yield 'two middles' => ['\left( a \middle| b \middle| c \right)'];
        yield 'bra-ket' => ['\left\langle a \middle| H \middle| b \right\rangle'];
        yield 'percent' => ['\left\{ x \% \middle| y \right\}'];
    }

    #[DataProvider('formulas')]
    public function test_a_middle_survives_mathml(string $tex): void
    {
        $a = TexReader::read($tex)->node;
        $xml = MathmlWriter::write($a);
        $back = MathmlReader::read($xml)->node;
        self::assertSame(TexWriter::write($a), TexWriter::write($back), $xml);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout($back, 18.0, $display)->width, 0.05, $xml);
        }
    }

    public function test_the_written_tex_keeps_the_middle_and_color(): void
    {
        foreach (['\left( a \middle| b \right)', '\left( a \middle\Vert b \right)', '\left( a \middle| \left[ b \middle| c \right] \right)', '\left( a \middle| b \middle| c \right)', '\left\langle a \middle| H \middle| b \right\rangle'] as $tex) {
            $back = MathmlReader::read(MathmlWriter::write(TexReader::read($tex)->node))->node;
            self::assertSame($tex, TexWriter::write($back));
        }
    }

    public function test_a_colored_middle_keeps_stretchy_and_its_color(): void
    {
        $a = TexReader::read('\left( a \color{red}\middle| b \right)')->node;
        $xml = MathmlWriter::write($a);
        self::assertStringContainsString('<mo mathcolor="#ff0000" stretchy="true" lspace="0.1667em" rspace="0.1667em">|</mo>', $xml);
        $back = MathmlReader::read($xml)->node;
        self::assertSame($xml, MathmlWriter::write($back));
        // Every sibling after it has the color, so TeX can say it as a switch.
        self::assertSame('\left( a \color{#ff0000}\middle| b \right)', TexWriter::write($back));
        self::assertSame(TexWriter::write($back), TexWriter::write(MathmlReader::read(MathmlWriter::write(TexReader::read(TexWriter::write($back))->node))->node));
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout($back, 18.0, $display)->width, 0.05);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function colorSwitches(): iterable
    {
        yield 'switch at the middle' => ['\left( a \color{red}\middle| b \right)', '\left( a \color{#ff0000}\middle| b \right)'];
        yield 'switch for the whole fence' => ['\left( \color{red} a \middle| b \right)', '\left( \color{#ff0000} a \middle| b \right)'];
        yield 'a later color change' => ['\left( a \color{red}\middle| b \color{blue} c \right)', '\left( a \color{#ff0000}\middle| b \color{#0000ff} c \right)'];
    }

    #[DataProvider('colorSwitches')]
    public function test_a_color_switch_keeps_the_middle_through_mathml(string $tex, string $expected): void
    {
        $a = TexReader::read($tex)->node;
        $back = MathmlReader::read(MathmlWriter::write($a))->node;
        self::assertSame($expected, TexWriter::write($back));
        // The written TeX reads back to the same MathML.
        self::assertSame(MathmlWriter::write($back), MathmlWriter::write(TexReader::read($expected)->node));
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout($back, 18.0, $display)->width, 0.05);
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout(TexReader::read(TexWriter::write($back))->node, 18.0, $display)->width, 0.05);
        }
    }

    public function test_two_bars_can_share_one_empty_row(): void
    {
        $back = MathmlReader::read('<math><mrow><mo>(</mo><mi>a</mi><mrow/><mo stretchy="true">|</mo><mrow/><mo stretchy="true">|</mo><mrow/><mi>b</mi><mo>)</mo></mrow></math>')->node;
        self::assertSame('\left( a \middle| \middle| b \right)', TexWriter::write($back));
    }

    public function test_a_colored_middle_with_an_uncolored_tail_falls_back_to_a_colored_bar(): void
    {
        $xml = '<math><mrow><mo stretchy="true">(</mo><mrow><mi>a</mi><mo mathcolor="#ff0000" stretchy="true" lspace="0.1667em" rspace="0.1667em">|</mo><mi>b</mi></mrow><mo stretchy="true">)</mo></mrow></math>';
        $tex = TexWriter::write(MathmlReader::read($xml)->node);
        // A switch would color b too; TeX cannot color a lone \middle, so it is a colored plain bar.
        self::assertSame('\left( a \textcolor{#ff0000}{|} b \right)', $tex);
    }

    /** @return iterable<string, array{string}> */
    public static function markers(): iterable
    {
        yield 'our spacing' => ['<mo stretchy="true" lspace="0.1667em" rspace="0.1667em">|</mo>'];
        yield 'mathjax empty rows' => ['<mrow data-mjx-texclass="CLOSE"></mrow><mo stretchy="true">|</mo><mrow data-mjx-texclass="OPEN"></mrow>'];
        yield 'empty rows without the attribute' => ['<mrow></mrow><mo stretchy="true">|</mo><mrow></mrow>'];
    }

    #[DataProvider('markers')]
    public function test_a_marked_bar_is_a_middle(string $bar): void
    {
        $back = MathmlReader::read('<math><mrow><mo stretchy="true">(</mo><mi>a</mi>' . $bar . '<mi>b</mi><mo stretchy="true">)</mo></mrow></math>')->node;
        self::assertSame('\left( a \middle| b \right)', TexWriter::write($back));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignBars(): iterable
    {
        yield 'stretchy only' => ['<mo stretchy="true">|</mo>'];
        yield 'fence attribute' => ['<mo stretchy="true" fence="true">|</mo>'];
        yield 'one empty row' => ['<mrow></mrow><mo stretchy="true">|</mo>'];
        yield 'dictionary default' => ['<mo>|</mo>'];
    }

    #[DataProvider('foreignBars')]
    public function test_another_tools_bar_is_not_a_middle(string $bar): void
    {
        $back = MathmlReader::read('<math><mrow><mo>(</mo><mi>a</mi>' . $bar . '<mi>b</mi><mo>)</mo></mrow></math>')->node;
        self::assertStringNotContainsString('\middle', TexWriter::write($back));
    }

    public function test_the_third_bar_of_an_unbalanced_run_is_not_a_middle(): void
    {
        $xml = '<math><mrow><mo>(</mo><mo stretchy="true">|</mo><mi>x</mi><mo stretchy="true">|</mo><mo>+</mo><mo stretchy="true">|</mo><mi>y</mi><mo>)</mo></mrow></math>';
        self::assertStringNotContainsString('\middle', TexWriter::write(MathmlReader::read($xml)->node));
    }

    /** @return iterable<string, array{string}> */
    public static function oneSided(): iterable
    {
        yield 'bar' => ['\left( \left| b \right. \right)'];
        yield 'bar and a middle' => ['\left( \left| b \middle| c \right. \right)'];
    }

    #[DataProvider('oneSided')]
    public function test_an_inner_fences_opener_is_not_a_middle(string $tex): void
    {
        $a = TexReader::read($tex)->node;
        $xml = MathmlWriter::write($a);
        $back = MathmlReader::read($xml)->node;
        self::assertStringNotContainsString('\left( \middle', TexWriter::write($back));
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout($back, 18.0, $display)->width, 0.05, $xml);
        }
    }

    public function test_the_text_has_no_double_space(): void
    {
        $text = TextWriter::write(TexReader::read('\left\{ x \% \middle| y \right\}')->node);
        self::assertStringNotContainsString('  ', $text);
        foreach (['=', '+', ','] as $op) {
            $text = TextWriter::write(TexReader::read('\left( a ' . $op . ' \middle| b \right)')->node);
            self::assertStringNotContainsString('  ', $text, $op);
            self::assertStringContainsString('|', $text);
        }
    }

    public function test_a_plain_bar_from_another_tool_is_not_a_middle(): void
    {
        $back = MathmlReader::read('<math><mrow><mi>a</mi><mo>|</mo><mi>b</mi></mrow></math>')->node;
        self::assertStringNotContainsString('\middle', TexWriter::write($back));
        self::assertSame('a | b', TexWriter::write($back));
        $back = MathmlReader::read('<math><mrow><mo>(</mo><mi>a</mi><mo>|</mo><mi>b</mi><mo>)</mo></mrow></math>')->node;
        self::assertStringNotContainsString('\middle', TexWriter::write($back));
    }

    public function test_a_whole_fence_color_is_not_split_for_a_middle_with_no_tex_spelling(): void
    {
        $node = new Fenced('(', ')', new Color(new Row([new Identifier('a'), new Middle('⟫'), new Identifier('b')]), '#ff0000'));
        $tex = TexWriter::write($node);
        self::assertSame(1, substr_count($tex, '\\textcolor') + substr_count($tex, '\\color'), $tex);
    }
}
