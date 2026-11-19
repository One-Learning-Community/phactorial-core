<?php

declare(strict_types=1);

namespace Phactorial\Tests\Node;

use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Node;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormsTest extends TestCase
{
    private static function mathml(string $tex): string
    {
        return MathmlWriter::write(Math::tex($tex));
    }

    /** The width a middle item takes, with its spacing: the whole row less the items either side. */
    private static function gap(Node $row, Node $left, Node $right): float
    {
        return Math::layout($row, 12.0, true)->width - Math::layout($left, 12.0, true)->width - Math::layout($right, 12.0, true)->width;
    }

    /** Width at 10pt in text style, the size the reviewed widths were taken at. */
    private static function w10(Node $n): float
    {
        return Math::layout($n, 10.0, false)->width;
    }

    /** @return iterable<string, array{string, string}> */
    public static function infixAfterFactorial(): iterable
    {
        yield 'equals' => ['n! = b', '<mo form="postfix">!</mo><mo>=</mo>'];
        yield 'plus' => ['n! + b', '<mo form="postfix">!</mo><mo>+</mo>'];
        yield 'le' => ['n! \le b', '<mo form="postfix">!</mo><mo>≤</mo>'];
        yield 'sim' => ['n! \sim b', '<mo form="postfix">!</mo><mo>∼</mo>'];
        yield 'double sim' => ['x \sim \sim b', '<mo rspace="0em">∼</mo><mo lspace="0em">∼</mo>'];
        yield 'after a paren' => ['(a) \sim b', '<mo>∼</mo>'];
    }

    #[DataProvider('infixAfterFactorial')]
    public function test_relation_or_binary_after_close_is_infix(string $tex, string $expected): void
    {
        $out = self::mathml($tex);
        self::assertStringContainsString($expected, $out);
        self::assertStringNotContainsString('form="prefix"', $out);
    }

    public function test_repeated_factorial_stays_postfix(): void
    {
        $out = self::mathml('n!!');
        self::assertStringNotContainsString('form="prefix"', $out);
        self::assertStringContainsString('<mo form="postfix">!</mo><mo>!</mo>', $out);
        self::assertStringContainsString('<mo form="postfix">!</mo><mo form="postfix">!</mo><mo>+</mo>', self::mathml('n!! + b'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function prefixKept(): iterable
    {
        yield 'a = -b' => ['a = -b', 1];
        yield 'a + + b' => ['a + + b', 1];
        yield 'a, -b' => ['a, -b', 1];
        yield 'to -infty' => ['x \to -\infty', 1];
        yield '-a' => ['-a', 0]; // first in its row: the writer leaves the form off
    }

    #[DataProvider('prefixKept')]
    public function test_prefix_operators_stay_prefix(string $tex, int $count): void
    {
        self::assertSame($count, substr_count(self::mathml($tex), 'form="prefix"'));
    }

    public function test_spacing_after_factorial_matches_plain_operand(): void
    {
        foreach (['\sim', '+', '='] as $op) {
            $plain = self::gap(Math::tex("a $op b"), Math::tex('a'), Math::tex('b'));
            $fact = self::gap(Math::tex("n! $op b"), Math::tex('n!'), Math::tex('b'));
            self::assertGreaterThan(5.0, $plain);
            self::assertEqualsWithDelta($plain, $fact, 1e-6, $op);
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function adjacentRelations(): iterable
    {
        yield 'sim sim' => ['x', '\sim', '\sim', 'b'];
        yield 'equals sim' => ['a', '=', '\sim', 'b'];
        yield 'le sim' => ['a', '\le', '\sim', 'b'];
        yield 'to sim' => ['p', '\to', '\sim', 'q'];
    }

    #[DataProvider('adjacentRelations')]
    public function test_adjacent_relations_are_spaced_as_in_tex(string $l, string $r1, string $r2, string $r): void
    {
        // One thick space either side of the pair: the gap of the first relation alone plus the second relation's width.
        $single = self::gap(Math::tex("$l $r1 $r"), Math::tex($l), Math::tex($r));
        $pair = self::gap(Math::tex("$l $r1 $r2 $r"), Math::tex($l), Math::tex($r));
        $glyph = Math::layout(Math::tex($r2), 12.0, true)->width;
        self::assertEqualsWithDelta($single + $glyph, $pair, 1e-6);
    }

    public function test_adjacent_relations_write_no_space_between_them_in_mathml(): void
    {
        $out = self::mathml('x \sim \sim b');
        self::assertStringContainsString('<mo rspace="0em">∼</mo><mo lspace="0em">∼</mo>', $out);
    }

    public function test_a_relation_inside_fences_loses_the_space_next_to_them(): void
    {
        $sim = Math::layout(Math::tex('\sim'), 12.0, true)->width;
        $open = Math::layout(Math::tex('\{'), 12.0, true)->width;
        $close = Math::layout(Math::tex('\}'), 12.0, true)->width;
        $w = Math::layout(Math::tex('\{ \sim \}'), 12.0, true)->width;
        self::assertEqualsWithDelta($open + $sim + $close, $w, 1e-6);
        $out = self::mathml('\{ \sim \}');
        self::assertStringContainsString('<mo lspace="0em" rspace="0em">∼</mo>', $out);
        self::assertStringContainsString('<mo lspace="0em">∼</mo>', self::mathml('\{ \sim b'));
        self::assertStringContainsString('<mo rspace="0em">∼</mo>', self::mathml('a \sim \}'));
    }

    public function test_a_space_between_relations_is_skipped_when_deciding_the_drop(): void
    {
        // TeX's spacing between atoms skips glue: Rel and Rel get nothing between them, so only the thin space stays.
        $out = self::mathml('x \sim \, \sim b');
        self::assertStringContainsString('rspace="0em"', $out);
        self::assertStringContainsString('lspace="0em"', $out);
        $plain = Math::layout(Math::tex('x \sim \sim b'), 12.0, true)->width;
        $spaced = Math::layout(Math::tex('x \sim \, \sim b'), 12.0, true)->width;
        self::assertEqualsWithDelta($plain + 3 * 12.0 / 18, $spaced, 1e-6);
        self::assertEqualsWithDelta(32.012, self::w10(Math::tex('x \sim \, \sim b')), 0.001);
    }

    public function test_an_explicit_side_wins_over_the_drop(): void
    {
        $out = MathmlWriter::write(Math::mathml('<math><mi>a</mi><mo rspace="0.5em">∼</mo><mo>∼</mo><mi>b</mi></math>'));
        self::assertStringContainsString('rspace="0.5em">∼</mo><mo lspace="0em">∼', $out);
    }

    /** @return iterable<string, array{string, string}> */
    public static function percentAndPostfix(): iterable
    {
        yield 'percent then plus' => ['50\% + 3', '<mo form="postfix">%</mo><mo>+</mo>'];
        yield 'percent then equals' => ['50\% = x', '<mo form="postfix">%</mo><mo>=</mo>'];
        // A guard: this passed before the fix, and must keep passing.
        yield 'percent last (guard)' => ['50\\%', '<mo>%</mo>'];
    }

    #[DataProvider('percentAndPostfix')]
    public function test_percent_ends_an_operand_before_an_operator(string $tex, string $expected): void
    {
        $out = self::mathml($tex);
        self::assertStringContainsString($expected, $out);
        self::assertStringNotContainsString('form="prefix"', $out);
    }

    public function test_percent_between_operands_stays_infix(): void
    {
        $out = self::mathml('a \% b');
        self::assertStringNotContainsString('form=', $out);
        self::assertStringContainsString('<mo>%</mo>', $out);
    }

    public function test_bars_are_not_disturbed(): void
    {
        $out = self::mathml('|x| \sim b');
        self::assertStringContainsString('form="postfix" stretchy="false">|</mo><mo>∼</mo>', $out);
        self::assertStringNotContainsString('form="prefix"', $out);
    }

    public function test_a_space_after_a_factorial_leaves_the_operator_infix(): void
    {
        $out = self::mathml('n! \, \sim b');
        self::assertStringContainsString('<mo>∼</mo>', $out);
        self::assertStringNotContainsString('form="prefix"', $out);
    }

    public function test_an_explicit_form_after_a_factorial_wins(): void
    {
        $out = MathmlWriter::write(Math::mathml('<math><mi>n</mi><mo>!</mo><mo form="prefix">+</mo><mi>b</mi></math>'));
        self::assertStringContainsString('<mo form="prefix">+</mo>', $out);
    }

    public function test_mathrel_and_mathbin_after_a_relation(): void
    {
        self::assertStringNotContainsString('form="prefix"', self::mathml('a = \mathrel{+} b'));
        // A Bin after a Rel is an Ord in TeX; this case is unchanged from before the operator spacing work.
        self::assertStringContainsString('form="prefix"', self::mathml('a = \mathbin{\sim} b'));
    }

    public function test_a_degree_sign_ends_an_operand(): void
    {
        $out = MathmlWriter::write(Math::mathml('<math><mn>90</mn><mo>°</mo><mo>+</mo><mn>3</mn></math>'));
        self::assertStringContainsString('<mo form="postfix">°</mo><mo>+</mo>', $out);
        self::assertStringNotContainsString('form="prefix"', $out);
    }

    public function test_an_ampersand_ends_an_operand(): void
    {
        $out = self::mathml('a \& -b');
        self::assertStringContainsString('<mo form="postfix">&amp;</mo><mo>−</mo>', $out);
    }

    public function test_mathml_input_resolves_the_same_way(): void
    {
        $node = Math::mathml('<math><mi>n</mi><mo>!</mo><mo>∼</mo><mi>b</mi></math>');
        $out = MathmlWriter::write($node);
        self::assertStringNotContainsString('form="prefix"', $out);
        $plain = self::gap(Math::mathml('<math><mi>a</mi><mo>∼</mo><mi>b</mi></math>'), Math::tex('a'), Math::tex('b'));
        $fact = self::gap($node, Math::tex('n!'), Math::tex('b'));
        self::assertEqualsWithDelta($plain, $fact, 1e-6);
    }

    /** Width at 18pt, where 1mu is 1pt. */
    private static function width(string $tex): float
    {
        return Math::layout(Math::tex($tex), 18.0, false)->width;
    }

    /** @return iterable<string, array{string}> */
    public static function roundTrips(): iterable
    {
        foreach (['x \sim \sim b', 'x \to\to\to b', '\{ \sim \}', 'a \mathrel{:}= b', 'a \mathrel{:}\mathrel{=} b', '\left( = b \right)', '\left( a = \right)',
            '\to\to b', 'a \to\to', 'a \subset\supset b', 'a =, b', 'a \% (b+c)', '\hspace{0.167em} \to \sim b', 'x \in \{ = \}', '\{ = \} a', '\left( = \right)', '\left( \sim \right)', '\left[ \to \right]'] as $tex) {
            yield $tex => [$tex];
        }
    }

    #[DataProvider('roundTrips')]
    public function test_mathml_reads_back_to_the_same_layout_and_tex(string $tex): void
    {
        $node = Math::tex($tex);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(Math::layout($node, 18.0, false)->width, Math::layout($back, 18.0, false)->width, 0.01, $tex);
        self::assertSame(TexWriter::write($node), TexWriter::write($back), $tex);
    }

    public function test_a_colored_drop_reads_back_to_the_same_width(): void
    {
        $node = Math::tex('a \sim \textcolor{red}{\sim} b');
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(Math::layout($node, 18.0, false)->width, Math::layout($back, 18.0, false)->width, 0.01);
    }

    public function test_a_mathord_between_relations_survives_the_round_trip(): void
    {
        // The writer gives it form="prefix", since it is no relation after an operator, so the reader leaves its zeros alone.
        foreach (['a \to\mathord{\to}\to b', '\to\mathord{\to}\to b', 'a \to\to\mathord{\to}'] as $tex) {
            $node = Math::tex($tex);
            $back = MathmlReader::read(MathmlWriter::write($node))->node;
            self::assertEqualsWithDelta(Math::layout($node, 18.0, false)->width, Math::layout($back, 18.0, false)->width, 0.01, $tex);
            self::assertSame(TexWriter::write($node), TexWriter::write($back), $tex);
        }
    }

    /** The TeX corpus entries whose MathML read-back already differed from the direct layout by more than rounding before the operator spacing work. */
    private const READ_BACK_DIFFERED = ['sizes', 'middle', 'math-fonts-more', 'sizing-more', 'rcases', 'delims-floor', 'delim-dot', 'epsilon-delta'];

    /** @return iterable<string, array{string}> */
    public static function corpusEntries(): iterable
    {
        foreach (array_keys(require __DIR__ . '/../fixtures/tex/corpus.php') as $key) {
            $name = (string) $key;
            if (! in_array($name, self::READ_BACK_DIFFERED, true)) {
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('corpusEntries')]
    public function test_corpus_mathml_reads_back_to_the_same_width(string $name): void
    {
        $node = TexReader::read((require __DIR__ . '/../fixtures/tex/corpus.php')[$name][0])->node;
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        // MathML spacing is written to four decimals of an em, so a few hundredths of a point are rounding.
        self::assertEqualsWithDelta(Math::layout($node, 12.0, true)->width, Math::layout($back, 12.0, true)->width, 0.05, $name);
    }

    public function test_relations_at_the_start_and_end_of_a_row_take_no_space_between_them(): void
    {
        $glyph = static fn (string $t): float => self::width('\mathord{' . $t . '}');
        self::assertEqualsWithDelta(self::width('\to b') + $glyph('\to'), self::width('\to\to b'), 1e-6);
        self::assertEqualsWithDelta(self::width('a \to') + $glyph('\to'), self::width('a \to\to'), 1e-6);
        self::assertEqualsWithDelta(self::width('a \subset b') + $glyph('\supset'), self::width('a \subset\supset b'), 1e-6);
        self::assertEqualsWithDelta(self::width('a \supset') + $glyph('\subset'), self::width('a \subset \supset'), 1e-6);
    }

    public function test_a_relation_with_an_explicit_form_keeps_the_form_but_not_the_space(): void
    {
        // An explicit form does not change TeX's atom class, so the space between the relations still goes.
        $node = Math::mathml('<math><mi>a</mi><mo>∼</mo><mo form="postfix">∼</mo><mi>b</mi></math>');
        self::assertStringContainsString('<mo form="postfix" lspace="0em">∼</mo>', MathmlWriter::write($node));
        $plain = Math::layout(Math::tex('a \sim \sim b'), 12.0, true)->width;
        self::assertEqualsWithDelta($plain, Math::layout($node, 12.0, true)->width, 1e-6);
    }

    public function test_percent_before_an_opening_fence_stays_infix(): void
    {
        $out = self::mathml('a \% (b+c)');
        self::assertStringNotContainsString('form="postfix">%', $out);
        self::assertStringContainsString('<mo>%</mo>', $out);
    }

    /** @return iterable<string, array{string}> */
    public static function percentEndsAnOperand(): iterable
    {
        yield 'close' => ['5\%)'];
        yield 'comma' => ['5\%, x'];
        yield 'cdot' => ['50\% \cdot 2'];
        yield 'equals' => ['5\% = x'];
    }

    #[DataProvider('percentEndsAnOperand')]
    public function test_percent_before_a_closing_fence_comma_or_operator_is_postfix(string $tex): void
    {
        self::assertStringContainsString('<mo form="postfix">%</mo>', self::mathml($tex));
    }

    /** @return iterable<string, array{string}> */
    public static function mathrelColon(): iterable
    {
        yield 'mathrel and equals' => ['a \mathrel{:}= b'];
        yield 'two mathrel' => ['a \mathrel{:}\mathrel{=} b'];
    }

    #[DataProvider('mathrelColon')]
    public function test_a_mathrel_class_does_not_block_the_drop(string $tex): void
    {
        // One thick space either side of the pair, none between.
        self::assertEqualsWithDelta(self::width('a \mathrel{:} b') + self::width('\mathord{=}'), self::width($tex), 1e-6);
        $out = self::mathml($tex);
        self::assertStringContainsString('rspace="0em"', $out);
        self::assertStringContainsString('lspace="0em"', $out);
    }

    public function test_an_explicit_other_side_still_blocks_the_drop_after_a_mathrel(): void
    {
        $node = Math::mathml('<math><mi>a</mi><mo rspace="0.5em">∼</mo><mo>∼</mo><mi>b</mi></math>');
        self::assertStringContainsString('rspace="0.5em"', MathmlWriter::write($node));
    }

    public function test_a_relation_first_or_last_in_a_fence_loses_the_space_next_to_it(): void
    {
        self::assertEqualsWithDelta(self::width('(= b)'), self::width('\left( = b \right)'), 1e-6);
        self::assertEqualsWithDelta(self::width('(a =)'), self::width('\left( a = \right)'), 1e-6);
        self::assertStringContainsString('lspace="0em"', self::mathml('\left( = b \right)'));
        self::assertStringContainsString('rspace="0em"', self::mathml('\left( a = \right)'));
        // An empty delimiter is no fence.
        self::assertStringNotContainsString('lspace="0em"', self::mathml('\left. = b \right)'));
    }

    public function test_a_relation_inside_a_fenced_fraction_keeps_its_space(): void
    {
        self::assertStringNotContainsString('lspace="0em"', self::mathml('\left( \frac{= b}{c} \right)'));
    }

    public function test_a_colored_relation_in_a_drop_keeps_its_color_and_the_width(): void
    {
        $plain = Math::layout(Math::tex('x \sim \sim b'), 12.0, true);
        $red = Math::layout(Math::tex('x \sim \textcolor{red}{\sim} b'), 12.0, true);
        self::assertEqualsWithDelta($plain->width, $red->width, 1e-6);
        $colors = [];
        $red->each(static function ($item) use (&$colors): void {
            $colors[$item->color ?? 'none'] = true;
        });
        self::assertArrayHasKey('#ff0000', $colors);
    }

    public function test_a_relation_before_punctuation_takes_no_space_after_it(): void
    {
        // Thick space before the relation, thin after the comma; none between them.
        $bare = self::width('a \mathord{=}\mathord{,} b');
        self::assertEqualsWithDelta($bare + 5.0 + 3.0, self::width('a =, b'), 1e-6);
        self::assertStringContainsString('<mo rspace="0em">=</mo><mo>,</mo>', self::mathml('a =, b'));
    }

    public function test_a_comma_after_a_relation_stays_infix(): void
    {
        self::assertStringNotContainsString('form="prefix"', self::mathml('a =, b'));
        self::assertSame('a = , b', \Phactorial\Render\TextWriter::write(Math::tex('a =, b')));
    }

    /** @return iterable<string, array{string, float}> TeX source and its width at 10pt (the width before operator spacing was reworked) */
    public static function mathordRelations(): iterable
    {
        yield 'f(a \\mathord{=})' => ['f(a \mathord{=})', 25.660];
        yield '[x \\mathord{\\sim}]' => ['[x \mathord{\sim}]', 19.580];
        yield 'a \\mathord{=} \\}' => ['a \mathord{=} \}', 16.020];
    }

    #[DataProvider('mathordRelations')]
    public function test_a_mathord_next_to_a_fence_or_punctuation_keeps_its_class_through_mathml(string $tex, float $width): void
    {
        $node = Math::tex($tex);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta($width, self::w10($node), 0.001);
        self::assertEqualsWithDelta($width, self::w10($back), 0.01);
        self::assertSame(TexWriter::write($node), TexWriter::write($back));
    }

    /** @return iterable<string, array{string, float, string}> */
    public static function handWrittenZeros(): iterable
    {
        yield 'two zero relations' => ['<math><mi>a</mi><mo lspace="0" rspace="0">=</mo><mo lspace="0" rspace="0">=</mo><mi>b</mi></math>', 25.130, 'a \mathord{=} \mathord{=} b'];
        yield 'a zero arrow after an opening bracket' => ['<math><mrow><mo>[</mo><mo lspace="0" rspace="0">→</mo><mi>b</mi><mo>]</mo></mrow></math>', 21.640, '\left[ \mathord{\to} b \right]'];
        yield 'a zero relation before a comma' => ['<math><mi>a</mi><mo lspace="0" rspace="0">=</mo><mo>,</mo><mi>b</mi></math>', 22.047, 'a \mathord{=} , b'];
    }

    #[DataProvider('handWrittenZeros')]
    public function test_a_hand_written_zero_that_is_no_drop_is_read_as_the_class_it_says(string $mathml, float $width, string $tex): void
    {
        $node = Math::mathml($mathml);
        self::assertEqualsWithDelta($width, self::w10($node), 0.001);
        self::assertSame($tex, TexWriter::write($node));
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta($width, self::w10($back), 0.01);
    }

    public function test_a_space_before_a_relation_class_arrow_keeps_the_width_through_mathml(): void
    {
        $node = Math::tex('\\, \\to \\sim b');
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(self::w10($node), self::w10($back), 0.01);
    }

    public function test_repeated_explicit_form_relations_keep_their_width_through_mathml(): void
    {
        $node = Math::mathml('<math><mi>a</mi><mo>=</mo><mo>=</mo><mo form="infix">=</mo><mi>b</mi></math>');
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(self::w10($node), self::w10($back), 0.01);
        self::assertEqualsWithDelta(self::w10(Math::tex('a = = = b')), self::w10($node), 0.001);
    }

    public function test_an_aligned_cell_drops_as_the_inline_row_does(): void
    {
        self::assertEqualsWithDelta(30.686, self::w10(Math::tex('a \sim\sim d')), 0.001);
        self::assertEqualsWithDelta(30.686, self::w10(Math::tex('\begin{aligned} a &\sim \sim d \end{aligned}')), 0.001);
        self::assertEqualsWithDelta(24.824, self::w10(Math::tex('a =, b')), 0.001);
        self::assertEqualsWithDelta(24.824, self::w10(Math::tex('\begin{aligned} a &=, b \end{aligned}')), 0.001);
    }

    public function test_a_relation_form_does_not_hide_a_leading_relation_in_the_row(): void
    {
        // A prefix entry of 0/0 (as `∼` has) does not make it less of a relation at the start of a row.
        self::assertEqualsWithDelta(self::width('\mathord{\sim}\mathord{\sim} b') + 5.0, self::width('\sim\sim b'), 1e-6);
        self::assertEqualsWithDelta(self::width('\left( \mathord{\sim}\mathord{\sim} b \right)') + 5.0, self::width('\left( \sim \sim b \right)'), 1e-6);
    }

    public function test_a_lone_relation_in_a_fence_takes_no_space_next_to_the_delimiters(): void
    {
        self::assertEqualsWithDelta(14.340, self::w10(Math::tex('\left( = \right)')), 0.001);
        self::assertEqualsWithDelta(self::width('(=)'), self::width('\left( = \right)'), 1e-6);
        self::assertEqualsWithDelta(self::width('(\sim)'), self::width('\left(\sim\right)'), 1e-6);
        $out = self::mathml('\left( = \right)');
        self::assertStringContainsString('<mo lspace="0em" rspace="0em">=</mo>', $out);
    }

    public function test_a_hand_written_zero_relation_between_fence_delimiters_is_a_drop(): void
    {
        $node = Math::mathml('<math><mrow><mo>(</mo><mo lspace="0" rspace="0">=</mo><mo>)</mo></mrow></math>');
        self::assertEqualsWithDelta(14.340, self::w10($node), 0.001);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(14.340, self::w10($back), 0.01);
        self::assertSame('\left( = \right)', TexWriter::write($node));
    }

    public function test_a_mathord_in_a_fence_keeps_its_width_through_mathml(): void
    {
        $node = Math::tex('\left( \mathord{=} \right)');
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        // In a fence a relation and a `\mathord` lay out alike, so the reader may read either back.
        self::assertEqualsWithDelta(14.340, self::w10($node), 0.001);
        self::assertEqualsWithDelta(14.340, self::w10($back), 0.01);
    }

    /** @return iterable<string, array{string}> */
    public static function fenceAndCellRoundTrips(): iterable
    {
        foreach (['\left( X, \sim \right)', '\left( a + \sim \right)', '\left( = \sim b \right)', '\left\| = \sim b \right\|', '\left( \to\to b \right)',
            '\left| = \right|', '\left\| = \right\|', '\left( \mathord{=} \sim b \right)'] as $tex) {
            yield $tex => [$tex];
        }
    }

    #[DataProvider('fenceAndCellRoundTrips')]
    public function test_a_fence_or_an_aligned_cell_reads_back_to_the_same_width_and_tex(string $tex): void
    {
        $node = Math::tex($tex);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(self::w10($node), self::w10($back), 0.01, $tex);
        self::assertSame(TexWriter::write($node), TexWriter::write($back), $tex);
    }

    /** @return iterable<string, array{string}> */
    public static function widthOnlyRoundTrips(): iterable
    {
        // An aligned cell reads back as a table of another kind, and a half-open fence is never rebuilt as a fence.
        foreach (['\begin{aligned} a &\sim\sim b \end{aligned}', '\begin{aligned} a &| \sim b \end{aligned}', '\left[ = \sim \right.', '\left[ \sim \right.', '\left[ = \right.', '\left. \sim \right]', '\left. \sim \sim \right]'] as $tex) {
            yield $tex => [$tex];
        }
    }

    #[DataProvider('widthOnlyRoundTrips')]
    public function test_an_aligned_cell_or_a_half_open_fence_reads_back_to_the_same_width(string $tex): void
    {
        $node = Math::tex($tex);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(self::w10($node), self::w10($back), 0.01, $tex);
    }

    public function test_a_hand_written_postfix_relation_in_a_fence_reads_back_as_the_relation(): void
    {
        $node = Math::mathml('<math><mrow><mo>(</mo><mo form="postfix">=</mo><mo>)</mo></mrow></math>');
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(self::w10($node), self::w10($back), 0.01);
        self::assertSame(TexWriter::write($node), TexWriter::write($back));
        self::assertStringNotContainsString('mathord', TexWriter::write($back));
    }

    public function test_limitation_a_mathrel_between_relations_reads_back_as_a_mathord(): void
    {
        // Dropped on both sides, a `\mathrel{:}` is written 0 and 0, as `\mathord{:}` is, and the reader cannot tell them apart.
        $node = Math::tex('a \leq \mathrel{:} \leq b');
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertStringContainsString('\mathrel{:}', TexWriter::write($node));
        self::assertStringContainsString('\mathord{:}', TexWriter::write($back));
    }

    /** @return iterable<string, array{string, float}> */
    public static function fenceShapedButNotRestored(): iterable
    {
        yield 'an opener and a row that starts with a relation' => ['<math><mrow><mo>(</mo><mrow><mo>=</mo><mi>b</mi></mrow></mrow></math>', 38.71];
        yield 'a row that ends with a relation and a closer' => ['<math><mrow><mrow><mi>a</mi><mo>=</mo></mrow><mo>)</mo></mrow></math>', 39.38];
    }

    #[DataProvider('fenceShapedButNotRestored')]
    public function test_a_row_beside_a_lone_delimiter_is_not_a_half_open_fence_unless_something_was_restored(string $mathml, float $width): void
    {
        self::assertEqualsWithDelta($width, Math::layout(Math::mathml($mathml), 18.0, false)->width, 0.01);
    }

    /** @return iterable<string, array{string, float}> */
    public static function bareChemistryArrows(): iterable
    {
        // The read-back widths are the ones before the operator spacing work: the arrow is a plain symbol, not a relation, so it takes no drops.
        yield 'in a fence' => ['(\ce{->})', 51.87];
        yield 'in a stretched fence' => ['\left( \ce{->} \right)', 51.87];
        yield 'in a double bar fence' => ['\left\| \ce{->} \right\|', 54.39];
        yield 'in a bar fence' => ['\left| \ce{->} \right|', 48.63];
        yield 'in a half-open fence' => ['\left[ \ce{->} \right.', 45.30];
        yield 'beside an operand in a fence' => ['\left( a \ce{->} \right)', 61.86];
        yield 'before a relation' => ['\ce{->} =', 61.98];
        yield 'before punctuation' => ['\ce{->},', 46.43];
        yield 'after a relation' => ['= \ce{->} a', 71.97];
        yield 'alone in a fence beside a color' => ['\left( \ce{->} \color{red} \right)', 51.87];
        yield 'two bare arrows in a row' => ['\ce{A -> -> B}', 102.08];
        yield 'two chemistry groups side by side' => ['\ce{->}\ce{->}', 78.03];
        yield 'two chemistry groups with a thin space between' => ['\ce{->}\,\ce{->}', 81.04];
    }

    #[DataProvider('bareChemistryArrows')]
    public function test_a_bare_chemistry_arrow_beside_a_relation_punctuation_or_fence_reads_back_without_the_drops(string $tex, float $width): void
    {
        $node = Math::tex($tex);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta($width, Math::layout($back, 18.0, false)->width, 0.01);
    }

    public function test_a_bare_chemistry_arrow_between_operands_is_written_as_it_was(): void
    {
        self::assertStringContainsString('<mi mathvariant="normal">A</mi><mo>⟶</mo><mi mathvariant="normal">B</mi>', self::mathml('\ce{A -> B}'));
    }

    public function test_a_zero_relation_after_a_relation_is_judged_against_its_neighbor_as_the_source_left_it(): void
    {
        // The first `=` keeps its right side: it is not facing an operator that the reader may restore a drop on.
        $node = Math::mathml('<math><mi>a</mi><mo>=</mo><mo lspace="0">=</mo><mi>b</mi></math>');
        self::assertEqualsWithDelta(60.23, Math::layout($node, 18.0, false)->width, 0.01);
    }

    /** @return iterable<string, array{string}> */
    public static function mathordInPlainFences(): iterable
    {
        yield '( \mathord{=} )' => ['( \mathord{=} )'];
        yield '\{ \mathord{=} \}' => ['\{ \mathord{=} \}'];
    }

    #[DataProvider('mathordInPlainFences')]
    public function test_a_mathord_between_plain_delimiters_keeps_its_class_through_mathml(string $tex): void
    {
        $node = Math::tex($tex);
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertSame(TexWriter::write($node), TexWriter::write($back));
        self::assertStringContainsString('\mathord{=}', TexWriter::write($back));
    }

    public function test_a_hand_written_mfenced_reads_its_content_with_the_delimiters_as_edges(): void
    {
        $node = Math::mathml('<math><mfenced><mo lspace="0">=</mo></mfenced></math>');
        self::assertEqualsWithDelta(self::w10(Math::tex('\left( = \right)')), self::w10($node), 0.01);
    }

    /** The chemistry corpus entries whose MathML read-back already differed from the direct layout by more than rounding before the operator spacing work (the stretchy and double arrows). */
    private const CHEM_READ_BACK_DIFFERED = [
        'combustion', 'acid-on-limestone', 'rusting', 'haber-process', 'precipitation', 'arrow-left', 'arrow-both',
        'arrow-to-and-fro', 'electron-transfer', 'unequal-forward', 'unequal-backward',
    ];

    /** @return iterable<string, array{string}> */
    public static function chemCorpusEntries(): iterable
    {
        foreach (array_keys(require __DIR__ . '/../fixtures/tex/chem-corpus.php') as $key) {
            $name = (string) $key;
            if (! in_array($name, self::CHEM_READ_BACK_DIFFERED, true)) {
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('chemCorpusEntries')]
    public function test_chem_corpus_mathml_reads_back_to_the_same_width(string $name): void
    {
        $node = TexReader::read('\ce{' . (require __DIR__ . '/../fixtures/tex/chem-corpus.php')[$name][0] . '}')->node;
        $back = MathmlReader::read(MathmlWriter::write($node))->node;
        self::assertEqualsWithDelta(Math::layout($node, 12.0, true)->width, Math::layout($back, 12.0, true)->width, 0.05, $name);
    }
}
