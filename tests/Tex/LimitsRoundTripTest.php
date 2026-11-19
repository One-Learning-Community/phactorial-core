<?php

declare(strict_types=1);

namespace Phactorial\Tests\Tex;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Identifier;
use Phactorial\Node\Node;
use Phactorial\Node\UnderOver;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;

final class LimitsRoundTripTest extends TestCase
{
    private const float TOLERANCE = 0.05;

    private static function node(string $tex): Node
    {
        return Math::tex($tex);
    }

    private static function width(Node $n, bool $display): float
    {
        return Math::layout($n, 18.0, $display)->width;
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function inputs(): iterable
    {
        yield 'sum limits' => ['\sum\limits_a^b x', '\sum\limits_a^b x', null];
        yield 'mathop int' => ['\mathop{\int}_0^1 x', '\int\displaylimits_0^1 x', '\int_0^1 x'];
        yield 'int displaylimits' => ['\int\displaylimits_0^1 x', '\int\displaylimits_0^1 x', '\int_0^1 x'];
        yield 'operatorname star' => ['\operatorname*{argmax}_x f', '\operatorname*{argmax}_x f', '\operatorname{argmax}_x f'];
        yield 'colored sum limits' => ['\textcolor{red}{\sum}\limits_a^b', null, '\sum\limits_a^b'];
        yield 'colored mathop int' => ['\mathop{\textcolor{red}{\int}}_0^1', null, '\int_0^1'];
        yield 'operatorname star over only' => ['\operatorname*{argmax}^x f', '\operatorname*{argmax}^x f', '\operatorname{argmax}^x f'];
        yield 'operatorname star limits' => ['\operatorname*{argmax}\limits_x f', null, null];
        yield 'lim limits' => ['\lim\limits_{x \to 0} f', '\lim\limits_{x \to 0} f', null];
        yield 'liminf operatorname limits' => ['\operatorname{liminf}\limits_x f', null, null];
        yield 'colored lim' => ['\textcolor{red}{\lim}_x f', null, null];
        yield 'bold lim' => ['\mathbf{\lim}_x f', null, null];
        yield 'bold lim limits' => ['\mathbf{\lim}\limits_x f', null, null];
        yield 'operatorname star one letter' => ['\operatorname*{E}_x f', '\operatorname*{E}_x f', '\operatorname{E}_x f'];
        yield 'operatorname star one letter both' => ['\operatorname*{E}_a^b f', '\operatorname*{E}_a^b f', null];
        yield 'mathop word displaylimits' => ['\mathop{x}\displaylimits_a x', null, '\mathop{x}_a x'];
    }

    #[DataProvider('inputs')]
    public function test_round_trips_in_both_styles(string $tex, ?string $spelling, ?string $sameAs): void
    {
        $node = self::node($tex);
        if ($spelling !== null) {
            self::assertSame($spelling, TexWriter::write($node));
        }
        $viaTex = self::node(TexWriter::write($node));
        $viaMathml = MathmlReader::read(MathmlWriter::write($node))->node;
        foreach ([true, false] as $display) {
            $w = self::width($node, $display);
            self::assertEqualsWithDelta($w, self::width($viaTex, $display), self::TOLERANCE, "TeX, display=$display");
            self::assertEqualsWithDelta($w, self::width($viaMathml, $display), self::TOLERANCE, "MathML, display=$display");
        }
        if ($sameAs !== null) {
            self::assertEqualsWithDelta(self::width(self::node($sameAs), false), self::width($node, false), self::TOLERANCE);
        }
    }

    public function test_a_plain_word_under_a_stack_stacks_in_every_style(): void
    {
        // `\mathrm{max}` is no function name, so \underset puts the label under it inline too, as TeX and a browser do.
        $node = self::node('\underset{x}{\mathrm{max}}');
        self::assertEqualsWithDelta(self::width($node, true), self::width($node, false), self::TOLERANCE);
        $viaMathml = MathmlReader::read(MathmlWriter::write($node))->node;
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($node, $display), self::width($viaMathml, $display), self::TOLERANCE);
            self::assertEqualsWithDelta(self::width($node, $display), self::width(self::node(TexWriter::write($node)), $display), self::TOLERANCE);
        }
    }

    public function test_a_roman_word_in_mathop_moves_its_limits_like_a_bold_one(): void
    {
        foreach (['\\mathop{\\mathrm{ab}}_a', '\\mathop{\\mathbf{ab}}_a'] as $tex) {
            $node = self::node($tex);
            self::assertLessThan(self::width($node, false) - 1.0, self::width($node, true), $tex);
        }
        self::assertEqualsWithDelta(self::width(self::node('\\mathop{\\mathrm{ab}}\\limits_a'), true), self::width(self::node('\\mathop{\\mathrm{ab}}\\limits_a'), false), self::TOLERANCE);
    }

    public function test_sum_limits_is_stacked_inline(): void
    {
        $stacked = self::width(self::node('\sum\limits_a^b x'), false);
        self::assertGreaterThan(1.0, abs($stacked - self::width(self::node('\sum_a^b x'), false)));
    }

    /**
     * Every base with every flag and script shape, in both styles, through TeX and MathML.
     *
     * @return iterable<string, array{string}>
     */
    public static function matrix(): iterable
    {
        $bases = ['\\sum', '\\int', '\\lim', '\\sin', '\\operatorname{argmax}', '\\operatorname*{argmax}', '\\operatorname*{E}', '\\mathop{x}', '\\mathop{\\mathrm{ab}}', '\\mathop{\\int}', '\\mathrm{max}', '\\mathrm{foo}', '\\mathbf{max}', '\\mathbf{foo}'];
        foreach ($bases as $base) {
            foreach ([$base, '\\textcolor{red}{' . $base . '}'] as $b) {
                foreach (['', '\\limits', '\\nolimits', '\\displaylimits'] as $flag) {
                    foreach (['_a', '^b', '_a^b'] as $scripts) {
                        $tex = $b . $flag . $scripts . ' f';
                        yield $tex => [$tex];
                    }
                }
            }
        }
    }

    #[DataProvider('matrix')]
    public function test_matrix_round_trips_in_both_styles(string $tex): void
    {
        $node = self::node($tex);
        $viaTex = self::node(TexWriter::write($node));
        $viaMathml = MathmlReader::read(MathmlWriter::write($node))->node;
        foreach ([true, false] as $display) {
            $w = self::width($node, $display);
            self::assertEqualsWithDelta($w, self::width($viaTex, $display), self::TOLERANCE, $tex . ' -> ' . TexWriter::write($node) . ', TeX, display=' . (int) $display);
            self::assertEqualsWithDelta($w, self::width($viaMathml, $display), self::TOLERANCE, $tex . ', MathML, display=' . (int) $display);
        }
    }

    public function test_a_word_that_is_not_a_function_name_and_a_marked_mo_take_a_mathop_before_a_control(): void
    {
        $nodes = [
            'identifier' => new UnderOver(new Identifier('foo', false, false, true), new Identifier('x'), null),
            'mathml lim' => MathmlReader::read('<math><mrow><mi mathvariant="normal">lim</mi><mi>x</mi></mrow></math>')->node,
            'mathrm lim' => self::node('\mathrm{lim}_x f'),
        ];
        foreach ($nodes as $label => $node) {
            $tex = TexWriter::write($node);
            // A control follows an operator (a \mathop, a command, or a starred name), never a bare symbol or \mathrm word.
            self::assertDoesNotMatchRegularExpression('~(\\\\mathrm\{[a-z]+\}|(?<![}a-z])[a-z])\\\\(displaylimits|limits)~', $tex, $label);
            $viaTex = self::node($tex);
            $viaMathml = MathmlReader::read(MathmlWriter::write($node))->node;
            foreach ([true, false] as $display) {
                $w = self::width($node, $display);
                self::assertEqualsWithDelta($w, self::width($viaTex, $display), self::TOLERANCE, "$label TeX $tex");
                self::assertEqualsWithDelta($w, self::width($viaMathml, $display), self::TOLERANCE, "$label MathML");
            }
        }
    }

    #[DataProvider('matrix')]
    public function test_no_control_follows_a_math_alphabet_group(string $tex): void
    {
        $written = TexWriter::write(self::node($tex));
        // Groups that make an operator (\mathop, \operatorname) may take a control; any other closing brace may not.
        $rest = preg_replace('~\\\\(?:mathop|operatorname\*?)(\{(?:[^{}]|(?1))*\})~', '', $written) ?? $written;
        self::assertDoesNotMatchRegularExpression('~\}\\\\(limits|nolimits|displaylimits)~', $rest, "$tex -> $written");
    }

    public function test_a_deeply_colored_operator_is_not_wrapped_needlessly(): void
    {
        $tex = str_repeat('\textcolor{red}{', 7) . '\sum' . str_repeat('}', 7) . '_a^b f';
        $written = TexWriter::write(self::node($tex));
        self::assertStringNotContainsString('\mathop', $written);
        self::assertStringNotContainsString('limits', $written);
    }

    public function test_an_italic_standard_name_that_is_not_a_function_stays_one_through_mathml(): void
    {
        foreach (['\mathit{max}_x f', '\mathit{max} x'] as $tex) {
            $node = self::node($tex);
            $back = MathmlReader::read(MathmlWriter::write($node))->node;
            foreach ([true, false] as $display) {
                self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $tex);
            }
        }
    }

    public function test_a_bold_limit_name_from_mathml_gets_a_mathop_before_any_control(): void
    {
        foreach (['max', 'lim'] as $name) {
            $node = MathmlReader::read('<math><munder><mi mathvariant="bold">' . $name . '</mi><mi>a</mi></munder></math>')->node;
            $tex = TexWriter::write($node);
            self::assertStringNotContainsString('}\\displaylimits', str_replace('\\mathop{\\mathbf{' . $name . '}}', '', $tex), $tex);
            $back = self::node($tex);
            foreach ([true, false] as $display) {
                self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $tex);
            }
        }
    }

    public function test_a_stacked_standard_function_is_written_with_its_own_command(): void
    {
        self::assertSame('\sin\limits_x f', TexWriter::write(self::node('\mathrm{sin}\limits_x f')));
    }

    public function test_a_control_after_an_ordinary_word_is_noted_and_read_as_the_operator_name(): void
    {
        foreach (['\mathrm{max}\limits_x f' => '\limits', '\mathrm{foo}\displaylimits_x f' => '\displaylimits'] as $tex => $element) {
            $notes = array_map(static fn ($n): string => $n->element, TexReader::read($tex)->notes());
            self::assertSame([$element], array_slice($notes, 0, 1), $tex);
        }
    }

    public function test_a_marked_mo_symbol_is_written_as_a_mathop(): void
    {
        $node = MathmlReader::read('<math><munder><mo movablelimits="true">x</mo><mi>a</mi></munder></math>')->node;
        // TeX's \mathop sets its own spacing, so the symbol's dictionary spacing is written as kerns outside the operator and its limits.
        self::assertSame('\mkern4mu \mathop{x}_a \mkern4mu', TexWriter::write($node));
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE);
        }
        $viaTex = self::node('\mathop{x}_a');
        self::assertGreaterThan(1.0, abs(self::width($viaTex, true) - self::width($viaTex, false)));
    }

    public function test_limits_after_a_name_stack_in_every_style(): void
    {
        foreach (['\operatorname*{argmax}\\limits_x f' => '\operatorname*{argmax}_x f', '\lim\\limits_{x \to 0} f' => '\lim_{x \to 0} f'] as $fixed => $moving) {
            $a = self::node($fixed);
            $b = self::node($moving);
            self::assertEqualsWithDelta(self::width($a, true), self::width($b, true), self::TOLERANCE, $fixed);
            self::assertGreaterThan(1.0, abs(self::width($a, false) - self::width($b, false)), $fixed);
        }
    }

    public function test_a_colored_limit_name_and_a_mathop_symbol_stack_like_the_bare_ones(): void
    {
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width(self::node('\lim_x f'), $display), self::width(self::node('\textcolor{red}{\lim}_x f'), $display), self::TOLERANCE);
        }
        $op = self::node('\mathop{x}_a x');
        self::assertGreaterThan(1.0, abs(self::width($op, true) - self::width($op, false)));
    }

    public function test_a_mathml_word_with_fixed_limits_keeps_its_own_attributes(): void
    {
        $node = MathmlReader::read('<math><mo movablelimits="false" lspace="1em">foo</mo></math>')->node;
        self::assertNotInstanceOf(Identifier::class, $node);
    }

    public function test_displaylimits_after_any_base_stacks_in_display_and_sits_beside_inline(): void
    {
        $node = self::node('\mathop{x}\displaylimits_a x');
        $plain = self::node('\mathop{x}_a x');
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($plain, $display), self::width($node, $display), self::TOLERANCE);
        }
        self::assertGreaterThan(1.0, abs(self::width($node, true) - self::width($node, false)));
    }

    public function test_mathml_sum_with_fixed_limits_round_trips(): void
    {
        $node = MathmlReader::read('<math><munderover><mo movablelimits="false">∑</mo><mi>a</mi><mi>b</mi></munderover></math>')->node;
        $tex = TexWriter::write($node);
        $back = self::node($tex);
        foreach ([true, false] as $display) {
            self::assertEqualsWithDelta(self::width($node, $display), self::width($back, $display), self::TOLERANCE, $tex);
        }
    }
}
