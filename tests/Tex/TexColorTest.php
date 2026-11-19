<?php

declare(strict_types=1);

namespace Phactorial\Tests\Tex;

use Phactorial\Layout\Box;
use Phactorial\Layout\Glyph;
use Phactorial\Math;
use Phactorial\Node\Color;
use Phactorial\Node\Fallback;
use Phactorial\Node\Fraction;
use Phactorial\Node\Identifier;
use Phactorial\Node\Node;
use Phactorial\Node\Row;
use Phactorial\Node\TexSource;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** \color and \textcolor read into Color nodes, keep their spelling, and write back. */
final class TexColorTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> spec spelling, hex, the model-less or model form */
    public static function accepted(): iterable
    {
        foreach ([
            ['red', '#ff0000'], ['#f00', '#ff0000'], ['#00FF00', '#00ff00'], ['[HTML]{2350FF}', '#2350ff'],
            ['[rgb]{1, 0, 0}', '#ff0000'], ['[RGB]{255,0,0}', '#ff0000'], ['[gray]{0}', '#000000'],
        ] as [$arg, $hex]) {
            $arg = str_starts_with($arg, '[') ? $arg : '{' . $arg . '}';
            yield '\color' . $arg => ['\color' . $arg, $hex, 'switch'];
            yield '\textcolor' . $arg => ['\textcolor' . $arg, $hex, 'command'];
        }
    }

    #[DataProvider('accepted')]
    public function test_accepted_forms_read_to_color_nodes(string $head, string $hex, string $form): void
    {
        if ($form === 'switch') {
            $tex = '{' . $head . ' x}';
            $node = Math::tex($tex);
            self::assertInstanceOf(TexSource::class, $node);
            self::assertSame('{' . $head . ' ', $node->tex);
            $color = $node->content;
        } else {
            $tex = $head . '{x}';
            $node = Math::tex($tex);
            self::assertInstanceOf(TexSource::class, $node);
            self::assertSame($head . '{', $node->tex);
            $color = $node->content;
        }
        self::assertInstanceOf(Color::class, $color);
        self::assertSame($hex, $color->color);
        self::assertSame($form === 'switch', $color->inline);
        self::assertEquals(new Identifier('x'), $color->content);
        self::assertSame('}', $node->close);
        self::assertSame([], TexReader::read($tex)->notes());
        self::assertSame($tex, TexWriter::write($node));
    }

    public function test_the_command_form_with_no_content_keeps_its_source(): void
    {
        $node = Math::tex('x^\color[HTML]{2350FF} y');
        self::assertInstanceOf(Row::class, $node);
        $script = $node->children[0]->sup ?? null;
        self::assertInstanceOf(TexSource::class, $script);
        self::assertEquals(new Row([]), $script->content);
        self::assertSame('\color[HTML]{2350FF}', $script->tex);
        self::assertSame('', $script->close);
        self::assertSame([], TexReader::read('\color{red}')->notes());
    }

    /** @return iterable<string, array{string}> */
    public static function roundTrips(): iterable
    {
        foreach ([
            '\textcolor{red}{x}', 'a {\color{blue} b + c} d', '\color[HTML]{2350FF} x', '\textcolor{red!50}{x}',
            'a \color{red}', 'x^{a \color{red} y}', '{\color{red}}', '{\color{red} }', '\color{red } x', '\textcolor{ red}{x}', '{\color{#00ff00} y}', '\textcolor[rgb]{1, 0, 0}{x}',
        ] as $t) {
            yield $t => [$t];
        }
    }

    #[DataProvider('roundTrips')]
    public function test_tex_round_trips_byte_identically(string $tex): void
    {
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
    }

    public function test_a_rejected_color_keeps_the_source_and_notes_once_per_spec(): void
    {
        $notes = TexReader::read('\textcolor{red!50}{x} \textcolor{red!50}{y} \textcolor{red!60}{z} {\color{nope} w}')->notes();
        self::assertSame(
            ['unknown color `red!50`; drawn in the default color', 'unknown color `red!60`; drawn in the default color', 'unknown color `nope`; drawn in the default color'],
            array_map(static fn ($n): string => $n->reason, $notes),
        );
        $node = Math::tex('\textcolor{red!50}{x}');
        self::assertInstanceOf(TexSource::class, $node);
        self::assertInstanceOf(Fallback::class, $node->content);
        self::assertSame('\textcolor{red!50}{', $node->tex);
    }

    /** @return iterable<string, array{string}> */
    public static function hostile(): iterable
    {
        foreach (['\color{\textbf{r}ed} x', '\textcolor{#12}{x}', '\textcolor[foo]{1}{x}', '\textcolor[HTML]{xyz}{x}', '\color{} x', '\textcolor{red\\\\}{x}', '\textcolor{}{x}', '\color[rgb]{2,0,0} x', '\color[HTML{ff0000} x', '\color{red" onload="x} y', '\textcolor{url(#a)}{x}', '\textcolor{\textbf{r}ed}{x}', '\textcolor{re(d}{x}', '\color{re"d} x'] as $t) {
            yield $t => [$t];
        }
    }

    #[DataProvider('hostile')]
    public function test_hostile_values_are_noted_and_survive_a_round_trip(string $tex): void
    {
        $once = Math::tex($tex);
        $written = TexWriter::write($once);
        $again = Math::tex($written);
        self::assertSame($written, TexWriter::write($again));
        self::assertStringNotContainsString('"', $written, $tex);
        self::assertStringNotContainsString('(', $written, $tex);
        // The written spelling is a fixed point: it reads back to the same tree and the same notes.
        self::assertSame(
            array_map(static fn ($n): string => $n->reason, TexReader::read($written)->notes()),
            array_map(static fn ($n): string => $n->reason, TexReader::read(TexWriter::write($again))->notes()),
            $tex,
        );
        self::assertSame(self::shape($once), self::shape($again), $tex);
        self::assertSame(self::shape($again), self::shape(Math::tex(TexWriter::write($again))));
    }

    public function test_the_hostile_values_produce_the_note(): void
    {
        foreach (['\textcolor{#12}{x}', '\textcolor[foo]{1}{x}', '\textcolor[HTML]{xyz}{x}', '\textcolor{}{x}', '\color[rgb]{2,0,0} x', '\color{red" onload="x} y', '\textcolor{url(#a)}{x}', '\textcolor{\textbf{r}ed}{x}', '\textcolor{red\\\\}{x}'] as $tex) {
            $notes = TexReader::read($tex)->notes();
            self::assertCount(1, $notes, $tex);
            self::assertStringStartsWith('unknown color `', $notes[0]->reason);
            $written = TexWriter::write(Math::tex($tex));
            self::assertStringNotContainsString('"', $written, $tex);
            self::assertStringNotContainsString('(', $written, $tex);
        }
    }

    public function test_a_rejected_group_names_its_spelling_with_question_marks(): void
    {
        $expected = [
            '\textcolor{re(d}{x}' => 're?d',
            '\color{re"d} x' => 're?d',
            '\color{red" onload="x} y' => 'red? onload??x',
            '\textcolor{\textbf{r}ed}{x}' => '?textbf?r?ed',
            '\textcolor{url(#a)}{x}' => 'url?#a?',
            '\color{rét} x' => 'r?t',
        ];
        foreach ($expected as $tex => $spec) {
            $notes = TexReader::read($tex)->notes();
            self::assertCount(1, $notes, $tex);
            self::assertSame('unknown color `' . $spec . '`; drawn in the default color', $notes[0]->reason, $tex);
            self::assertStringContainsString('{}', TexWriter::write(Math::tex($tex)), $tex);
        }
    }

    public function test_one_note_serves_both_commands_for_the_same_spec(): void
    {
        self::assertCount(1, TexReader::read('\color{red!50} x \textcolor{red!50}{y}')->notes());
        self::assertCount(2, TexReader::read('\color{red!50} x \textcolor{red!60}{y}')->notes());
    }

    public function test_empty_groups_lay_out_to_width_zero(): void
    {
        foreach (['\textcolor{red}{}', '{\color{red} }', '\color{red}'] as $tex) {
            self::assertSame(0.0, Math::layout(Math::tex($tex), 12.0, true)->width, $tex);
        }
    }

    public function test_a_bare_color_writes_as_a_command_or_a_group(): void
    {
        self::assertSame('\textcolor{#ff0000}{x}', TexWriter::write(new Color(new Identifier('x'), '#ff0000')));
        self::assertSame('{\color{#ff0000} a + b}', TexWriter::write(new Color(Math::row('a + b'), '#ff0000', true)));
        self::assertSame('x {\color{#00ff00} y}', TexWriter::write(new Row([new Identifier('x'), new Color(new Identifier('y'), '#00ff00', true)])));
        // What the writer produces reads back to a color.
        $back = Math::tex('\textcolor{#ff0000}{x}');
        self::assertInstanceOf(TexSource::class, $back);
        self::assertInstanceOf(Color::class, $back->content);
    }

    private static function shape(Node $n): string
    {
        return $n::class . '(' . implode(',', array_map(self::shape(...), $n->children())) . ')' . ($n instanceof TexSource ? $n->tex . '|' . $n->close : '');
    }

    public function test_a_color_switch_runs_past_middle_to_right(): void
    {
        $tex = '\left( a \color{red} \middle| b \right)';
        $node = Math::tex($tex);
        self::assertSame([], Math::notes($node));
        self::assertSame($tex, TexWriter::write($node));

        $plain = Math::layout(Math::tex('\left( a \middle| b \right)'));
        $glyphs = static function (Box $box): array {
            $out = [];
            $box->each(static function ($item, float $dx, float $dy) use (&$out): void {
                if ($item instanceof Glyph) {
                    $out[] = [$item->gid, $item->color, round($dx + $item->x, 3), round($dy + $item->y, 3)];
                }
            });

            return $out;
        };
        $colored = $glyphs(Math::layout($node));
        $uncolored = $glyphs($plain);
        self::assertSame(array_column($uncolored, 0), array_column($colored, 0));
        self::assertSame(array_column($uncolored, 2), array_column($colored, 2));
        // ( a | b ): only the bar and b are red.
        self::assertSame([null, null, '#ff0000', '#ff0000', null], array_column($colored, 1));
    }

    public function test_a_rejected_switch_before_middle_lays_out_as_uncolored(): void
    {
        $glyphs = static function (Node $node): array {
            $out = [];
            Math::layout($node)->each(static function ($item, float $dx, float $dy) use (&$out): void {
                if ($item instanceof Glyph) {
                    $out[] = [$item->gid, $item->color, round($dx + $item->x, 3), round($dy + $item->y, 3)];
                }
            });

            return $out;
        };
        $tex = '\left( a \color{bogus} \middle| \frac{b}{c} \right)';
        $node = Math::tex($tex);
        self::assertSame($glyphs(Math::tex('\left( a \middle| \frac{b}{c} \right)')), $glyphs($node));
        self::assertSame($tex, TexWriter::write($node));
    }

    public function test_a_switch_that_fills_a_fence_with_a_middle_keeps_its_color(): void
    {
        // The fence's whole content is the switch: ( a | b ), all but the parentheses red.
        self::assertSame([null, '#ff0000', '#ff0000', '#ff0000', null], self::colorsOf('\\left( \\color{red} a \\middle| b \\right)'));
    }

    #[DataProvider('middleSpellings')]
    public function test_a_switch_before_middle_round_trips_byte_for_byte(string $tex): void
    {
        self::assertSame($tex, TexWriter::write(Math::tex($tex)));
    }

    /** @return iterable<string, array{string}> */
    public static function middleSpellings(): iterable
    {
        foreach (['bogus', 'red'] as $color) {
            yield $color . ' spaced' => ['\\left( a \\color{' . $color . '} \\middle| b \\right)'];
            yield $color . ' tight' => ['\\left( a \\color{' . $color . '}\\middle| b \\right)'];
        }
    }

    /** @return iterable<string, array{string, string}> source, first write */
    public static function switchArguments(): iterable
    {
        yield 'script' => ['x^\\color{red} y', 'x^{\\color{red}} y'];
        yield 'sqrt' => ['\\sqrt\\color{red} x', '\\sqrt{\\color{red}} x'];
        yield 'frac' => ['\\frac\\color{red} b', '\\frac{\\color{red}}{b}'];
        yield 'braced script' => ['x^{\\color{red} y}', 'x^{\\color{red} y}'];
        yield 'braced frac' => ['\\frac{\\color{red} a}{b}', '\\frac{\\color{red} a}{b}'];
    }

    #[DataProvider('switchArguments')]
    public function test_a_color_switch_as_a_script_or_argument_round_trips_stably(string $tex, string $first): void
    {
        $one = TexWriter::write(Math::tex($tex));
        self::assertSame($first, $one);
        self::assertSame($one, TexWriter::write(Math::tex($one)));
    }

    public function test_over_after_a_color_switch_takes_the_whole_group(): void
    {
        $node = Math::tex('a \\color{red} \\over b');
        self::assertInstanceOf(Fraction::class, $node);
        self::assertInstanceOf(Row::class, $node->numerator);
        self::assertCount(2, $node->numerator->children);
        self::assertEquals(new Identifier('b'), $node->denominator);
        self::assertSame('\\frac{a \\color{red}}{b}', TexWriter::write($node));
        self::assertEquals(Math::layout(Math::tex('\\frac{a \\color{red}}{b}')), Math::layout($node));

        $both = Math::tex('a \\color{red} b \\over c');
        self::assertInstanceOf(Fraction::class, $both);
        self::assertSame('\\frac{a \\color{red} b}{c}', TexWriter::write($both));
        $colored = [];
        Math::layout($both)->each(static function ($item) use (&$colored): void {
            if ($item instanceof Glyph) {
                $colored[] = $item->color;
            }
        });
        self::assertContains('#ff0000', $colored);
    }

    /**
     * Glyph colors in drawing order: '#rrggbb' or null.
     *
     * @return list<?string>
     */
    private static function colorsOf(string $tex): array
    {
        $out = [];
        Math::layout(Math::tex($tex))->each(static function ($item) use (&$out): void {
            if ($item instanceof Glyph) {
                $out[] = $item->color;
            }
        });

        return $out;
    }

    /** @return iterable<string, array{string, string}> source, first write */
    public static function wrappedSwitches(): iterable
    {
        // Font and class commands leave no braces of their own in the output, so the switch keeps its group.
        yield 'mathrm' => ['\\mathrm{\\color{red} x} y', '{\\color{red} \\mathrm{x}} y'];
        yield 'mathit' => ['\\mathit{\\color{red} x} y', '{\\color{red} \\mathit{x}} y'];
        yield 'boldsymbol' => ['\\boldsymbol{\\color{red} x} y', '{\\color{red} \\boldsymbol{x}} y'];
        yield 'mathbb' => ['\\mathbb{\\color{red} x} y', '{\\color{red} \\mathbb{x}} y'];
        yield 'mathop' => ['\\mathop{\\color{red} x} y', '{\\color{red} \\mathop{x}} y'];
        yield 'mathrel' => ['\\mathrel{\\color{red} x} y', '{\\color{red} \\mathrel{x}} y'];
        yield 'mathchoice' => ['\\mathchoice{\\color{red} a}{b}{c}{d} y', '{{\\color{red} a} b c d} y'];
        yield 'sideset' => ['\\sideset{}{}{\\color{red} x} y', '{\\color{red} x} y'];
        yield 'sideset with a script' => ['\\sideset{_a}{}{\\color{red} x} y', '{}_a{{\\color{red} x}} y'];
        yield 'prescript' => ['\\prescript{}{}{\\color{red} x} y', '{\\color{red} x} y'];
        yield 'unbraced mathrm' => ['\\mathrm\\color{red} x y', '{\\color{red}} x y'];
        yield 'unbraced sideset' => ['\\sideset{}{}\\color{red} x y', '{\\color{red}} x y'];
        yield 'unbraced mathop' => ['\\mathop\\color{red} x y', '{\\color{red}} x y'];
        // Commands that write their own braces back keep the switch bare inside them.
        yield 'hat' => ['\\hat{\\color{red} x} y', '\\hat{\\color{red} x} y'];
        yield 'vec' => ['\\vec{\\color{red} x} y', '\\vec{\\color{red} x} y'];
        yield 'overline' => ['\\overline{\\color{red} x} y', '\\overline{\\color{red} x} y'];
        yield 'boxed' => ['\\boxed{\\color{red} x} y', '\\boxed{\\color{red} x} y'];
        yield 'cancel' => ['\\cancel{\\color{red} x} y', '\\cancel{\\color{red} x} y'];
        yield 'phantom' => ['\\phantom{\\color{red} x} y', '\\phantom{\\color{red} x} y'];
        yield 'overset' => ['\\overset{\\color{red} a}{x} y', '\\overset{\\color{red} a}{x} y'];
        yield 'underset' => ['\\underset{\\color{red} a}{x} y', '\\underset{\\color{red} a}{x} y'];
        yield 'stackrel' => ['\\stackrel{\\color{red} a}{x} y', '\\overset{\\color{red} a}{x} y'];
        yield 'underbrace' => ['\\underbrace{\\color{red} x} y', '\\underbrace{\\color{red} x} y'];
        yield 'binom' => ['\\binom{\\color{red} a}{b} y', '\\binom{\\color{red} a}{b} y'];
        yield 'tfrac' => ['\\tfrac{\\color{red} a}{b} y', '{\\textstyle \\frac{\\color{red} a}{b}} y'];
        yield 'dfrac' => ['\\dfrac{\\color{red} a}{b} y', '{\\displaystyle \\frac{\\color{red} a}{b}} y'];
        yield 'xrightarrow' => ['\\xrightarrow{\\color{red} a} y', '\\xrightarrow{\\color{red} a} y'];
        yield 'sqrt index' => ['\\sqrt[\\color{red} 3]{x} y', '\\sqrt[\\color{red} 3]{x} y'];
        yield 'textcolor' => ['\\textcolor{blue}{\\color{red} x} y', '\\textcolor{blue}{\\color{red} x} y'];
        yield 'font inside hat' => ['\\hat{\\mathrm{\\color{red} x}} y', '\\hat{{\\color{red} \\mathrm{x}}} y'];
        yield 'hat inside font' => ['\\mathrm{\\hat{\\color{red} x}} y', '\\hat{\\color{red} \\mathrm{x}} y'];
    }

    #[DataProvider('wrappedSwitches')]
    public function test_a_switch_inside_an_argument_colors_only_the_argument(string $tex, string $first): void
    {
        $one = TexWriter::write(Math::tex($tex));
        self::assertSame($first, $one);
        self::assertSame($one, TexWriter::write(Math::tex($one)));
        self::assertSame(self::colorsOf($tex), self::colorsOf($one));
    }

    public function test_over_after_a_colored_middle_takes_only_what_follows_it(): void
    {
        $glyphs = static function (string $tex): array {
            $out = [];
            Math::layout(Math::tex($tex))->each(static function ($item, float $dx, float $dy) use (&$out): void {
                if ($item instanceof Glyph) {
                    $out[] = [$item->gid, round($dx + $item->x, 3), round($dy + $item->y, 3)];
                }
            });

            return $out;
        };
        $tex = '\\left( \\color{red} a \\middle| b \\over c \\right)';
        $one = TexWriter::write(Math::tex($tex));
        self::assertSame('\\left( \\color{red} a \\middle| \\frac{b}{c} \\right)', $one);
        self::assertSame($one, TexWriter::write(Math::tex($one)));
        // Same glyphs at the same places as uncolored: the bar still stretches, and the fraction holds only b and c.
        self::assertSame($glyphs('\\left( a \\middle| b \\over c \\right)'), $glyphs($tex));
        self::assertSame($glyphs($tex), $glyphs($one));
        // ( a | b c ): all but the parentheses are red.
        $colors = self::colorsOf($tex);
        self::assertSame([null, ...array_fill(0, count($colors) - 2, '#ff0000'), null], $colors);
        self::assertSame($colors, self::colorsOf($one));

        $after = '\\left( a \\color{red} \\middle| b \\over c \\right)';
        self::assertSame('\\left( a \\color{red} \\middle| \\frac{b}{c} \\right)', TexWriter::write(Math::tex($after)));
        self::assertSame($glyphs('\\left( a \\middle| b \\over c \\right)'), $glyphs($after));
    }
}
