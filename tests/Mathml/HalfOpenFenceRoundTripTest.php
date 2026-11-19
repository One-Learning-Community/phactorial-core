<?php

declare(strict_types=1);

namespace Phactorial\Tests\Mathml;

use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A fence with `\left.` or `\right.` written to MathML reads back as the same fence. */
final class HalfOpenFenceRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formulas(): iterable
    {
        yield 'open right' => ['\left[ a = b \right.'];
        yield 'tall content' => ['\left[ \frac{a}{b} = c \right.'];
        yield 'open left with script' => ['\left. \frac{a}{b} \right|_0'];
        yield 'open left' => ['\left. a = b \right)'];
        yield 'cases' => ['\left\{ \begin{matrix} a \\\\ b \end{matrix} \right.'];
        yield 'both empty' => ['\left. a \right.'];
        yield 'with middle' => ['\left[ a \middle| b \right.'];
        yield 'middle, open left' => ['\left. a \middle| b \right)'];
        yield 'one-sided inner' => ['\left( \left| b \right. \right)'];
        yield 'one-sided inner, open left' => ['\left( \left. b \right| \right)'];
        yield 'half open inside a fence' => ['\left( \left[ a \right. + b \right)'];
        yield 'half open beside half open' => ['\left[ a \right. \left. b \right)'];
        yield 'colored middle, open left' => ['\left. a \color{#ff0000}\middle| b \right)'];
        yield 'colored middle, open right' => ['\left( a \color{#ff0000}\middle| b \right.'];
    }

    #[DataProvider('formulas')]
    public function test_a_half_open_fence_survives_mathml(string $tex): void
    {
        $a = TexReader::read($tex)->node;
        $xml = MathmlWriter::write($a);
        $back = MathmlReader::read($xml)->node;
        self::assertSame(TexWriter::write($a), TexWriter::write($back), $xml);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout($back, 18.0, $display)->width, 0.05, $xml);
        }
    }

    public function test_the_missing_side_is_an_empty_stretchy_fence(): void
    {
        $xml = MathmlWriter::write(TexReader::read('\left[ a \right.')->node);
        self::assertStringContainsString('<mo fence="true" stretchy="true" lspace="0" rspace="0"></mo>', $xml);
    }

    public function test_the_tex_is_unchanged(): void
    {
        foreach (['\left[ a = b \right.', '\left. a \right)', '\left( \left| b \right. \right)', '\left. a \middle| b \right)'] as $tex) {
            $back = MathmlReader::read(MathmlWriter::write(TexReader::read($tex)->node))->node;
            self::assertSame($tex, TexWriter::write($back));
        }
    }

    public function test_mathjax_shapes_of_an_empty_delimiter_read_as_fences(): void
    {
        foreach ([
            '<math><mrow><mo data-mjx-texclass="OPEN">[</mo><mi>a</mi><mo data-mjx-texclass="CLOSE" fence="true" stretchy="true" symmetric="true"></mo></mrow></math>',
            '<math><mrow><mo fence="true" stretchy="true" symmetric="true"></mo><mi>a</mi><mo data-mjx-texclass="CLOSE">)</mo></mrow></math>',
        ] as $xml) {
            self::assertStringContainsString('\\right', TexWriter::write(MathmlReader::read($xml)->node), $xml);
            self::assertStringContainsString('\\left', TexWriter::write(MathmlReader::read($xml)->node), $xml);
        }
        $back = TexWriter::write(MathmlReader::read('<math><mrow><mo fence="true" stretchy="true" symmetric="true"></mo><mi>a</mi><mo>)</mo></mrow></math>')->node);
        self::assertSame('\left. a \right)', $back);
    }

    public function test_the_old_marker_without_zero_spacing_still_reads(): void
    {
        $back = TexWriter::write(MathmlReader::read('<math><mrow><mo stretchy="true">[</mo><mi>a</mi><mo fence="true" stretchy="true"></mo></mrow></math>')->node);
        self::assertSame('\left[ a \right.', $back);
    }

    public function test_an_empty_mo_with_only_a_texclass_is_nothing(): void
    {
        $back = TexWriter::write(MathmlReader::read('<math><mrow><mo data-mjx-texclass="OPEN"></mo><mi>a</mi><mo>)</mo></mrow></math>')->node);
        self::assertStringNotContainsString('\\left', $back);
    }

    public function test_a_plain_unmatched_opener_stays_plain(): void
    {
        $back = TexWriter::write(MathmlReader::read('<math><mo>(</mo><mi>a</mi></math>')->node);
        self::assertStringNotContainsString('\\left', $back);
        self::assertStringStartsWith('(', $back);
    }

    public function test_an_empty_mo_without_a_fence_mark_is_still_nothing(): void
    {
        $back = TexWriter::write(MathmlReader::read('<math><mrow><mo>(</mo><mi>a</mi><mo></mo></mrow></math>')->node);
        self::assertStringNotContainsString('\\left', $back);
    }
}
