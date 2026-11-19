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

/** A fixed-size delimiter (`\big`, `\Big`, ...) keeps its size through MathML and does not pair into a fence. */
final class BigDelimiterRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formulas(): iterable
    {
        yield 'Big parens' => ['\Big( a \Big)'];
        yield 'Big parens with sim' => ['\Big( \sim a \Big)'];
        yield 'bigl brackets' => ['\bigl[ x \bigr]'];
        yield 'Bigg bars' => ['\Bigg| x \Bigg|'];
        yield 'sized bar next to a middle' => ['\left( a \middle| b \Big| c \right)'];
    }

    #[DataProvider('formulas')]
    public function test_a_big_delimiter_survives_mathml(string $tex): void
    {
        $a = TexReader::read($tex)->node;
        $xml = MathmlWriter::write($a);
        $back = MathmlReader::read($xml)->node;
        self::assertSame(TexWriter::write($a), TexWriter::write($back), $xml);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(Math::layout($a, 18.0, $display)->width, Math::layout($back, 18.0, $display)->width, 0.05, $xml);
        }
    }

    public function test_unsized_delimiters_still_pair(): void
    {
        $node = MathmlReader::read('<math><mrow><mo>(</mo><mi>a</mi><mo>)</mo></mrow></math>')->node;
        self::assertSame('\left( a \right)', TexWriter::write($node));
    }

    /** @return iterable<string, array{string, string}> */
    public static function readings(): iterable
    {
        $big = 'stretchy="true" minsize="1.2em" maxsize="1.2em"';
        yield 'equal sizes in different units' => ['<math><mrow><mo stretchy="true" minsize="1.8em" maxsize="0.3in">(</mo><mi>a</mi><mo stretchy="true" minsize="1.8em" maxsize="0.3in">)</mo></mrow></math>', '\\Bigl( a \\Bigr)'];
        yield 'em and mm' => ['<math><mrow><mo stretchy="true" minsize="1.2em" maxsize="5.08mm">[</mo><mi>a</mi><mo stretchy="true" minsize="1.2em" maxsize="5.08mm">]</mo></mrow></math>', '\\bigl[ a \\bigr]'];
        yield 'MathJax OPEN and CLOSE wrappers' => ["<math><mrow data-mjx-texclass=\"OPEN\"><mo $big>(</mo></mrow><mi>a</mi><mrow data-mjx-texclass=\"CLOSE\"><mo $big>)</mo></mrow></math>", '\\bigl( a \\bigr)'];
        yield 'sized opener, plain closer' => ['<math><mrow><mo stretchy="true" minsize="1.8em" maxsize="1.8em">(</mo><mi>a</mi><mo>)</mo></mrow></math>', '\\Bigl( a )'];
        yield 'plain opener, sized closer' => ['<math><mrow><mo>(</mo><mi>a</mi><mo stretchy="true" minsize="1.8em" maxsize="1.8em">)</mo></mrow></math>', '( a \\Bigr)'];
    }

    #[DataProvider('readings')]
    public function test_how_sized_delimiters_read(string $xml, string $tex): void
    {
        self::assertSame($tex, TexWriter::write(MathmlReader::read($xml)->node), $xml);
    }
}
