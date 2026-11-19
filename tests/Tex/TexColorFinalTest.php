<?php

declare(strict_types=1);

namespace Phactorial\Tests\Tex;

use Phactorial\Layout\Box;
use Phactorial\Layout\Glyph;
use Phactorial\Math;
use Phactorial\Node\Node;
use Phactorial\Render\TexWriter;
use PHPUnit\Framework\TestCase;

/** Colored constructs read from TeX keep the layout and the TeX of their uncolored twins. */
final class TexColorFinalTest extends TestCase
{
    /** @return list<array{int, float, float}> glyph id and position, in drawing order */
    private static function positions(Box $box): array
    {
        $out = [];
        $box->each(static function ($item, float $dx, float $dy) use (&$out): void {
            if ($item instanceof Glyph) {
                $out[] = [$item->gid, round($dx + $item->x, 3), round($dy + $item->y, 3)];
            }
        });

        return $out;
    }

    private static function layout(Node $n): Box
    {
        return Math::layout($n, 12.0, true);
    }

    public function test_a_colored_large_operator_keeps_its_limits(): void
    {
        $plain = self::layout(Math::tex('\displaystyle \sum_i^n x'));
        $colored = self::layout(Math::tex('\displaystyle \textcolor{red}{\sum}_i^n x'));

        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);
        self::assertSame(self::positions($plain), self::positions($colored));
    }

    public function test_a_colored_large_operator_writes_valid_tex_and_round_trips(): void
    {
        $tex = TexWriter::write(Math::tex('\displaystyle \textcolor{red}{\sum}_i^n x'));
        self::assertStringNotContainsString('\nolimits', $tex);
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        self::assertEqualsWithDelta(
            self::layout(Math::tex('\displaystyle \sum_i^n x'))->width,
            self::layout(Math::tex($tex))->width,
            1e-9,
        );
    }

    public function test_mathml_colored_operator_with_limits_keeps_them_through_tex(): void
    {
        $node = Math::mathml('<math display="block"><munderover><mo mathcolor="red">∑</mo><mi>i</mi><mi>n</mi></munderover><mi>x</mi></math>');
        $tex = TexWriter::write($node);
        self::assertStringNotContainsString('\nolimits', $tex);
        self::assertEqualsWithDelta(self::layout($node)->width, self::layout(Math::tex('\displaystyle ' . $tex))->width, 1e-9);
    }

    public function test_nolimits_is_written_only_after_a_bare_operator(): void
    {
        foreach (['\textcolor{red}{\sum}\nolimits_i^n x', '\textcolor{red}{\lim}_i x'] as $tex) {
            $written = TexWriter::write(Math::tex($tex));
            self::assertStringNotContainsString('\nolimits', $written, $tex);
            self::assertSame($written, TexWriter::write(Math::tex($written)), $tex);
            self::assertEqualsWithDelta(self::layout(Math::tex($tex))->width, self::layout(Math::tex($written))->width, 1e-9, $tex);
        }
        self::assertSame('\sum\nolimits_i^n', TexWriter::write(Math::tex('\sum\nolimits_i^n')));
    }

    /** @return iterable<string, array{string}> */
    public static function coloredIntegrals(): iterable
    {
        yield 'int' => ['\\textcolor{red}{\\int}_0^1 x'];
        yield 'oint' => ['\\textcolor{red}{\\oint}_C x'];
        yield 'display int' => ['\\displaystyle \\textcolor{red}{\\int}_0^1 x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coloredIntegrals')]
    public function test_a_colored_integral_keeps_its_limits_beside_it(string $tex): void
    {
        $bare = str_replace(['\\textcolor{red}{\\int}', '\\textcolor{red}{\\oint}'], [' \\int ', ' \\oint '], $tex);
        self::assertSame(self::positions(self::layout(Math::tex($bare))), self::positions(self::layout(Math::tex($tex))), $tex);

        $written = TexWriter::write(Math::tex($tex));
        self::assertDoesNotMatchRegularExpression('/\\\\(textcolor|color)\{[^{}]*\}(\{[^{}]*\})?\\\\(no)?limits/', $written, $tex);
        self::assertSame($written, TexWriter::write(Math::tex($written)), $tex);
        self::assertSame(self::positions(self::layout(Math::tex($tex))), self::positions(self::layout(Math::tex($written))), $tex);
    }

    public function test_a_colored_mathop_integral_stacks_its_limits_like_the_uncolored_one(): void
    {
        $tex = '\\mathop{\\textcolor{red}{\\int}}_0^1 x';
        self::assertSame(self::positions(self::layout(Math::tex('\\mathop{\\int}_0^1 x'))), self::positions(self::layout(Math::tex($tex))));

        $written = TexWriter::write(Math::tex($tex));
        self::assertSame('\\mathop{\\textcolor{red}{\\int}}\\displaylimits_0^1 x', $written);
        self::assertSame($written, TexWriter::write(Math::tex($written)));
        self::assertSame(self::positions(self::layout(Math::tex($tex))), self::positions(self::layout(Math::tex($written))));
    }

    public function test_colored_integrals_match_the_uncolored_width(): void
    {
        self::assertEqualsWithDelta(self::layout(Math::tex('\\int_0^1 x'))->width, self::layout(Math::tex('\\textcolor{red}{\\int}_0^1 x'))->width, 1e-9);
        self::assertEqualsWithDelta(self::layout(Math::tex('\\oint_C x'))->width, self::layout(Math::tex('\\textcolor{red}{\\oint}_C x'))->width, 1e-9);
    }

    public function test_mathop_on_an_integral_spaces_as_the_integral_does(): void
    {
        self::assertSame(
            self::positions(self::layout(Math::tex('\\int\\limits_a^b x'))),
            self::positions(self::layout(Math::tex('\\mathop{\\int}\\limits_a^b x'))),
        );
    }

    public function test_mathml_colored_integral_with_limits_writes_valid_tex(): void
    {
        $node = Math::mathml('<math display="block"><munderover><mo mathcolor="red">∫</mo><mi>a</mi><mi>b</mi></munderover><mi>x</mi></math>');
        $tex = TexWriter::write($node);
        self::assertDoesNotMatchRegularExpression('/\\\\(textcolor|color)\{[^{}]*\}(\{[^{}]*\})?\\\\(no)?limits/', $tex);
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        self::assertSame(self::positions(self::layout($node)), self::positions(self::layout(Math::tex($tex))));
    }

    public function test_a_class_command_sees_through_a_color(): void
    {
        $plain = self::layout(Math::tex('a \mathbin{x} b'));
        $colored = self::layout(Math::tex('a \mathbin{\textcolor{red}{x}} b'));
        self::assertEqualsWithDelta($plain->width, $colored->width, 1e-9);

        $written = TexWriter::write(Math::tex('a \mathbin{\textcolor{red}{x}} b'));
        self::assertStringContainsString('\mathbin', $written);
        self::assertStringContainsString('\textcolor{red}', $written);
        self::assertSame($written, TexWriter::write(Math::tex($written)));
        self::assertEqualsWithDelta($plain->width, self::layout(Math::tex($written))->width, 1e-9);
    }

    /** @return iterable<string, array{string, string, string}> source, the plain twin, the reason its note gives */
    public static function classedWithNotes(): iterable
    {
        yield 'a color with an unknown name' => ['a \\mathbin{\\textcolor{red!50}{x}} b', 'a \\mathbin{x} b', 'red!50'];
        yield 'a switch with an unknown name' => ['a \\mathbin{\\color{red!50} x} b', 'a \\mathbin{x} b', 'red!50'];
        yield 'a box' => ['a \\mathbin{\\boxed{x}} b', 'a \\mathbin{x} b', 'the box is not drawn'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classedWithNotes')]
    public function test_a_class_command_keeps_the_notes_of_what_it_wraps(string $tex, string $plain, string $reason): void
    {
        $reasons = array_map(static fn ($n): string => $n->reason, \Phactorial\MathRender::fromTex($tex)->notes());
        self::assertNotEmpty(array_filter($reasons, static fn (string $r): bool => str_contains($r, $reason)), $tex);

        self::assertEqualsWithDelta(self::layout(Math::tex($plain))->width, self::layout(Math::tex($tex))->width, 1e-9, $tex);
        self::assertSame(self::positions(self::layout(Math::tex($plain))), self::positions(self::layout(Math::tex($tex))), $tex);
    }

    /** @return iterable<string, array{string, bool}> MathML and whether the operator is red */
    public static function displayStyleIntegrals(): iterable
    {
        $mo = static fn (string $color): string => '<mstyle displaystyle="true"' . $color . '><mo>∫</mo></mstyle>';
        foreach ([['plain', '', false], ['colored', ' mathcolor="red"', true]] as [$name, $color, $red]) {
            yield $name . ' munderover' => ['<math display="block"><munderover>' . $mo($color) . '<mi>a</mi><mi>b</mi></munderover><mi>x</mi></math>', $red];
            yield $name . ' munder' => ['<math display="block"><munder>' . $mo($color) . '<mi>a</mi></munder><mi>x</mi></math>', $red];
            yield $name . ' mover' => ['<math display="block"><mover>' . $mo($color) . '<mi>b</mi></mover><mi>x</mi></math>', $red];
        }
    }

    /** @return list<?string> the fill of each glyph, in drawing order */
    private static function fills(Box $box): array
    {
        $out = [];
        $box->each(static function ($item) use (&$out): void {
            if ($item instanceof Glyph) {
                $out[] = $item->color;
            }
        });

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('displayStyleIntegrals')]
    public function test_a_display_style_operator_keeps_its_limits_on_the_tex_round_trip(string $mathml, bool $red): void
    {
        $node = Math::mathml($mathml);
        $tex = TexWriter::write($node);
        self::assertStringContainsString('\\limits', $tex);
        self::assertStringStartsWith('\\mathop{', $tex);
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        self::assertSame(self::positions(self::layout($node)), self::positions(self::layout(Math::tex($tex))));

        self::assertSame($red, str_contains($tex, '#ff0000'));
        $fills = self::fills(self::layout(Math::tex($tex)));
        self::assertSame(self::fills(self::layout($node)), $fills);
        self::assertSame($red, in_array('#ff0000', $fills, true));
    }

    public function test_a_colored_mathop_with_limits_round_trips(): void
    {
        $tex = '\mathop{\textcolor{red}{\sum}}\limits_a^b';
        $written = TexWriter::write(Math::tex($tex));
        self::assertSame($written, TexWriter::write(Math::tex($written)));
        self::assertSame(self::positions(self::layout(Math::tex($tex))), self::positions(self::layout(Math::tex($written))));
        self::assertEqualsWithDelta(
            self::layout(Math::tex('\mathop{\sum}\limits_a^b'))->width,
            self::layout(Math::tex($tex))->width,
            1e-9,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function scriptBases(): iterable
    {
        yield 'identifier' => ['<math><msup><mi mathcolor="red">x</mi><mn>2</mn></msup></math>', '\textcolor{#ff0000}{x}^2'];
        yield 'operator' => ['<math><msub><mo mathcolor="red">+</mo><mi>i</mi></msub></math>', '\textcolor{#ff0000}{+}_i'];
        yield 'several tokens keep their braces' => ['<math><msup><mrow mathcolor="red"><mi>x</mi><mi>y</mi></mrow><mn>2</mn></msup></math>', '{\textcolor{#ff0000}{x y}}^2'];
        yield 'an integral needs none' => ['<math><msub><mo mathcolor="red">∫</mo><mi>i</mi></msub></math>', '\\textcolor{#ff0000}{\\int}_i'];
        yield 'a large operator keeps its braces' => ['<math><msub><mo mathcolor="red">∑</mo><mi>i</mi></msub></math>', '{\textcolor{#ff0000}{\sum}}_i'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scriptBases')]
    public function test_a_lone_colored_token_takes_its_script_without_braces(string $mathml, string $expected): void
    {
        $node = Math::mathml($mathml);
        $tex = TexWriter::write($node);
        self::assertSame($expected, $tex);
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        self::assertSame(self::positions(self::layout($node)), self::positions(self::layout(Math::tex($tex))));
    }

    public function test_a_colored_token_script_from_tex_is_byte_stable(): void
    {
        foreach (['\textcolor{red}{x}^2', '\textcolor{red}{x}_i^2'] as $tex) {
            self::assertSame($tex, TexWriter::write(Math::tex($tex)));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function middles(): iterable
    {
        yield 'a bar then a dot' => ['\left( \color{red} a \middle| b \middle. c \over d \right)', '\left( a \middle| b \middle. c \over d \right)'];
        yield 'a dot' => ['\left( \color{red} a \middle. b \over c \right)', '\left( a \middle. b \over c \right)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('middles')]
    public function test_a_dot_middle_ends_what_a_colored_fraction_takes(string $colored, string $plain): void
    {
        self::assertSame(
            str_replace('\color{red} ', '', TexWriter::write(Math::tex($colored))),
            TexWriter::write(Math::tex($plain)),
        );
        self::assertEqualsWithDelta(self::layout(Math::tex($plain))->width, self::layout(Math::tex($colored))->width, 1e-9);
    }
}
