<?php

declare(strict_types=1);

namespace Phactorial\Tests\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Color;
use Phactorial\Node\Identifier;
use Phactorial\Node\Node;
use Phactorial\Node\Operator;
use Phactorial\Node\Row;
use Phactorial\Node\Scripts;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;

final class OperatorSpacingTexTest extends TestCase
{
    private const float TOLERANCE = 0.05;

    private static function width(Node $n, bool $display): float
    {
        return Math::layout($n, 18.0, $display)->width;
    }

    private static function back(string $tex): Node
    {
        return Math::tex($tex);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inputs(): iterable
    {
        yield 'integral lspace' => ['<math><mi>x</mi><mo lspace="0">∫</mo><mi>x</mi></math>'];
        yield 'integral msubsup lspace' => ['<math><mi>x</mi><msubsup><mo lspace="0">∫</mo><mn>0</mn><mn>1</mn></msubsup><mi>x</mi></math>'];
        yield 'integral munderover lspace' => ['<math><mi>x</mi><munderover><mo lspace="0">∫</mo><mn>0</mn><mn>1</mn></munderover><mi>x</mi></math>'];
        yield 'sum munderover lspace' => ['<math><mi>x</mi><munderover><mo lspace="0">∑</mo><mn>0</mn><mn>1</mn></munderover><mi>x</mi></math>'];
        yield 'plus rspace' => ['<math><mi>a</mi><mo rspace="0">+</mo><mi>b</mi></math>'];
        yield 'plus lspace' => ['<math><mi>a</mi><mo lspace="0">+</mo><mi>b</mi></math>'];
        yield 'movablelimits x' => ['<math><munder><mo movablelimits="true">x</mo><mi>a</mi></munder></math>'];
        yield 'movablelimits plus' => ['<math><munder><mo movablelimits="true">+</mo><mi>a</mi></munder></math>'];
        yield 'explicit form is not written' => ['<math><mi>a</mi><mo lspace="0" form="prefix">+</mo><mi>b</mi></math>'];
        yield 'underset base' => ['<math><mi>a</mi><munder><mo lspace="0">=</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'underset kern base' => ['<math><mi>a</mi><munder><mo lspace="0" rspace="2">+</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'nested underset base' => ['<math><mi>a</mi><munder><munder><mo lspace="0">=</mo><mi>i</mi></munder><mi>j</mi></munder><mi>b</mi></math>'];
        yield 'rel class limits movable' => ['<math><mi>a</mi><munder><mo lspace="thickmathspace" rspace="thickmathspace">∑</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'rel class limits fixed' => ['<math><mi>a</mi><munder><mo movablelimits="false" lspace="thickmathspace" rspace="thickmathspace">∑</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'movable plus thick' => ['<math><mi>a</mi><munder><mo movablelimits="true" lspace="thickmathspace" rspace="thickmathspace">+</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'movable equals medium' => ['<math><mi>a</mi><munder><mo movablelimits="true" lspace="mediummathspace" rspace="mediummathspace">=</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'movable equals zero' => ['<math><mi>a</mi><munder><mo movablelimits="true" lspace="0" rspace="0">=</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'letter base scripts' => ['<math><mi>a</mi><msub><mo>x</mo><mi>i</mi></msub><mi>b</mi></math>'];
        yield 'letter base stack' => ['<math><mi>a</mi><munder><mo lspace="0">x</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'letter in a row' => ['<math><mi>a</mi><mo lspace="thickmathspace">x</mo><mi>b</mi></math>'];
        yield 'unspaced letter in a row' => ['<math><mi>a</mi><mo>x</mo><mi>b</mi></math>'];
        yield 'colored letter in a row' => ['<math><mi>a</mi><mstyle mathcolor="red"><mo lspace="0">x</mo></mstyle><mi>b</mi></math>'];
        yield 'colored one-sided plus in a row' => ['<math><mi>a</mi><mstyle mathcolor="red"><mo lspace="0">+</mo></mstyle><mi>b</mi></math>'];
        yield 'large operator spaced one mu' => ['<math><mi>a</mi><munder><mo lspace="0.0555556em" rspace="0.0555556em">∑</mo><mi>i</mi></munder><mi>b</mi></math>'];
        yield 'sum rspace limits' => ['<math><mi>x</mi><munder><mo rspace="0">∑</mo><mi>a</mi></munder><mi>x</mi></math>'];
    }

    #[DataProvider('inputs')]
    public function test_width_survives_tex_writing(string $mathml): void
    {
        $node = MathmlReader::read($mathml)->node;
        $tex = TexWriter::write($node);
        $back = self::back($tex);
        foreach ([false, true] as $display) {
            self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $tex . ($display ? ' (display)' : ' (inline)'));
        }
    }

    public function test_tex_born_letters_write_unchanged(): void
    {
        foreach (['d x', '\Delta x', 'a \mathrel{x} b', 'a + b'] as $tex) {
            self::assertSame($tex, TexWriter::write(self::back($tex)));
        }
    }

    public function test_class_spellings_stay_unchanged(): void
    {
        self::assertSame('a \mathrel{:} b', TexWriter::write(self::back('a \mathrel{:} b')));
        self::assertSame('a \mathbin{=} b', TexWriter::write(self::back('a \mathbin{=} b')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function classedThroughMathml(): iterable
    {
        yield 'rel plus' => ['a \mathrel{+} b'];
        yield 'rel colon' => ['a \mathrel{:} b'];
        yield 'bin equals' => ['a \mathbin{=} b'];
        yield 'op plus with a script' => ['a \mathop{+}_i b'];
        yield 'rel equals with a script' => ['a \mathrel{=}_i b'];
    }

    #[DataProvider('classedThroughMathml')]
    public function test_class_spellings_survive_the_four_decimal_em_of_mathml(string $tex): void
    {
        $back = MathmlReader::read(MathmlWriter::write(self::back($tex)))->node;
        self::assertSame($tex, TexWriter::write($back));
    }

    public function test_mathml_spacing_within_rounding_of_a_class_writes_the_class_and_no_kern(): void
    {
        foreach (['0.2778em', '0.278em'] as $em) {
            $mathml = '<math><mi>a</mi><msub><mo lspace="' . $em . '" rspace="' . $em . '">=</mo><mi>i</mi></msub><mi>b</mi></math>';
            $tex = TexWriter::write(MathmlReader::read($mathml)->node);
            self::assertStringNotContainsString('mkern', $tex, $em);
            self::assertSame('a \mathrel{=}_i b', $tex, $em);
        }
    }

    public function test_a_colored_scripted_operator_keeps_its_kerns_outside_the_color(): void
    {
        $op = new Operator('=', null, null, null, null, 1.5, 0.0);
        $node = new Row([new Identifier('a'), new Color(new Scripts($op, new Identifier('i'), null), '#ff0000'), new Identifier('b')]);
        $tex = TexWriter::write($node);
        self::assertSame('a \mkern-3.5mu \textcolor{#ff0000}{=_i} \mkern-5mu b', $tex);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($node, $display), self::width(self::back($tex), $display), self::TOLERANCE);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function classedLargeOperators(): iterable
    {
        yield 'rel beside' => ['a \mathrel{\sum}_a b'];
    }

    #[DataProvider('classedLargeOperators')]
    public function test_class_wrapped_large_operator_keeps_its_class(string $tex): void
    {
        self::assertSame($tex, TexWriter::write(self::back($tex)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function controlAfterClass(): iterable
    {
        yield 'rel limits' => ['a \mathrel{\sum}\limits_a b', 'a \mkern2mu \sum\limits_a \mkern2mu b', '\limits'];
        yield 'rel limits no scripts' => ['a \mathrel{\sum}\limits b', 'a \mathrel{\sum} b', '\limits'];
        yield 'colored rel limits' => ['a \textcolor{red}{\mathrel{\sum}}\limits_a b', 'a \mkern2mu \mathop{\textcolor{red}{\sum}}\limits_a \mkern2mu b', '\limits'];
        yield 'negated rel limits' => ['a \not\mathrel{\sum}\limits_a b', 'a \underset{a}{\mathrel{\not\sum}} b', '\limits'];
        yield 'bin limits' => ['a \mathbin{\sum}\limits_a b', 'a \mkern1mu \sum\limits_a \mkern1mu b', '\limits'];
    }

    #[DataProvider('controlAfterClass')]
    public function test_control_after_a_class_atom_is_noted_and_written_legally(string $tex, string $written, string $element): void
    {
        $r = TexReader::read($tex);
        $notes = array_map(static fn ($n): string => $n->element . ': ' . $n->reason, $r->notes());
        self::assertSame([$element . ': a limit control must follow an operator'], $notes);
        self::assertSame($written, TexWriter::write($r->node));
    }

    #[DataProvider('inputs')]
    public function test_no_limits_control_follows_a_class_group(string $mathml): void
    {
        $tex = TexWriter::write(MathmlReader::read($mathml)->node);
        self::assertDoesNotMatchRegularExpression('/\\\\math(?:rel|bin|punct|ord|open|close)\{[^{}]*\}\\\\(?:no|display)?limits/', $tex);
    }

    public function test_nested_stacks_keep_one_group(): void
    {
        $node = MathmlReader::read('<math><mi>a</mi><munder><munder><mo lspace="0">=</mo><mi>i</mi></munder><mi>j</mi></munder><mi>b</mi></math>')->node;
        self::assertSame('a \underset{j}{{\mkern-5mu \underset{i}{=}}} b', TexWriter::write($node));
    }

    public function test_kerns_stay_outside_a_stacked_base(): void
    {
        $node = MathmlReader::read('<math><mi>a</mi><munder><mo lspace="0">=</mo><mi>i</mi></munder><mi>b</mi></math>')->node;
        $tex = TexWriter::write($node);
        self::assertStringContainsString('\\underset{i}{=}', $tex);
        self::assertStringNotContainsString('{\\mkern', $tex);
        self::assertStringContainsString('\\mkern', $tex);
    }

    public function test_colored_limits_keep_their_control_and_width(): void
    {
        foreach (['\textcolor{red}{\sum}\limits_a^b', '\mathop{\textcolor{red}{\int}}_0^1'] as $tex) {
            $node = self::back($tex);
            $written = TexWriter::write($node);
            $back = self::back($written);
            foreach ([false, true] as $display) {
                self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $written);
            }
        }
    }

    public function test_limits_control_still_follows_the_operator(): void
    {
        $node = MathmlReader::read('<math><munderover><mo lspace="0">∑</mo><mn>0</mn><mn>1</mn></munderover></math>')->node;
        $tex = TexWriter::write($node);
        self::assertDoesNotMatchRegularExpression('/\\\\mkern[^\\\\]*\\\\(?:no|display)?limits/', $tex);
    }
}
