<?php

declare(strict_types=1);

namespace Phactorial\Tests\Tex;

use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;

final class TextArgumentCommandsTest extends TestCase
{
    public function test_color_in_an_operator_name_is_dropped_with_a_note(): void
    {
        $tex = '\operatorname{\color{red} sn}';
        self::assertSame('\operatorname{sn}', TexWriter::write(Math::tex($tex)));
        $reasons = array_map(static fn ($n): string => $n->reason, TexReader::read($tex)->notes());
        self::assertCount(1, $reasons);
        self::assertStringContainsString('\color', $reasons[0]);
    }

    public function test_textcolor_in_an_operator_name_keeps_its_second_argument(): void
    {
        self::assertSame('\operatorname{sn}', TexWriter::write(Math::tex('\operatorname{\textcolor{red}{sn}}')));
    }

    public function test_tag_label_drops_commands_with_their_names(): void
    {
        self::assertSame('x \tag{1}', TexWriter::write(Math::tex('x \tag{\color{red} 1}')));
        self::assertSame('x \tag{1}', TexWriter::write(Math::tex('x \tag{\textbf{1}}')));
        self::assertSame('x \tag{1}', TexWriter::write(Math::tex('x \tag{\textcolor{red}{1}}')));
    }

    public function test_color_specs_with_a_model_are_skipped(): void
    {
        self::assertSame('\operatorname{sn}', TexWriter::write(Math::tex('\operatorname{\color[rgb]{1,0,0} sn}')));
        self::assertSame('\operatorname{sn}', TexWriter::write(Math::tex('\operatorname{\textcolor[HTML]{FF0000}{sn}}')));
        self::assertSame('x \tag{1}', TexWriter::write(Math::tex('x \tag{\color[rgb]{1,0,0} 1}')));
        self::assertSame('x \tag{1}', TexWriter::write(Math::tex('x \tag{\textcolor[HTML]{FF0000}{1}}')));
        self::assertSame('x \tag{1}', TexWriter::write(Math::tex('x \tag{\textcolor{red}{\textbf{1}}}')));
    }

    public function test_tag_keeps_symbols_and_escaped_characters(): void
    {
        foreach (['\alpha', '\dagger', '50\%', '\#1', '\alpha x'] as $label) {
            self::assertSame('x \tag{' . $label . '}', TexWriter::write(Math::tex('x \tag{' . $label . '}')), $label);
        }
    }

    public function test_tag_symbol_never_fuses_with_a_later_letter(): void
    {
        $rows = [
            '\alpha{}x' => '\alpha x', '{\alpha}x' => '\alpha x', '\alpha\,x' => '\alpha x',
            '\alpha\textbf{x}' => '\alpha x', '\alpha\color{red}x' => '\alpha x', '\alpha\foo x' => '\alpha x',
            '\textcolor[rgb]{1,0,0}{\beta}c' => '\beta c',
        ];
        foreach ($rows as $in => $out) {
            $tex = TexWriter::write(Math::tex('x \tag{' . $in . '}'));
            self::assertSame('x \tag{' . $out . '}', $tex, $in);
            self::assertSame($tex, TexWriter::write(Math::tex($tex)), $in);
        }
    }

    public function test_unclosed_color_model_does_not_swallow_the_formula(): void
    {
        self::assertStringContainsString('y', TexWriter::write(Math::tex('x \tag{\color[rgb 1} y')));
    }

    public function test_unbraced_tag_label_is_sanitized(): void
    {
        foreach (['$', '#', '~'] as $c) {
            self::assertSame('x \tag{}', TexWriter::write(Math::tex('x \tag' . $c)), $c);
        }
    }

    public function test_stray_middle_gets_a_note_naming_left(): void
    {
        $notes = TexReader::read('{x \middle| y}')->notes();
        self::assertCount(1, $notes);
        self::assertStringContainsString('\left', $notes[0]->reason);
    }

    public function test_middle_inside_a_fence_has_no_note(): void
    {
        self::assertSame([], TexReader::read('\left( x \middle| y \right)')->notes());
        self::assertSame([], TexReader::read('\left. x \middle| y \right)')->notes());
    }
}
