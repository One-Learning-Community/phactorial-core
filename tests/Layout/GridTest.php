<?php

declare(strict_types=1);

namespace Phactorial\Tests\Layout;

use Phactorial\Layout\{Box, Glyph, Rule};
use Phactorial\Math;
use Phactorial\Node\{Grid, Node, Scripts};
use PHPUnit\Framework\TestCase;

final class GridTest extends TestCase
{
    private const SIZE = 12.0;

    private static function axis(): float
    {
        return Math::stixTwoMath()->math()->constant('axisHeight') * self::SIZE / 1000;
    }

    private static function lay(Node $n, bool $display = true): Box
    {
        return Math::layout($n, self::SIZE, $display);
    }

    /** @return list<Glyph> */
    private static function glyphs(Box $box): array
    {
        return array_values(array_filter($box->items, static fn ($i): bool => $i instanceof Glyph));
    }

    /**
     * Where a cell, laid out in text style, ended up inside $grid: its first item's position there minus alone.
     *
     * @return array{float, float} dx, dy (dy is the cell's baseline)
     */
    private static function shiftOf(Box $grid, Node $cell): array
    {
        $a = self::lay($cell, false)->items[0];
        foreach ($grid->items as $i) {
            if ($a instanceof Rule && $i instanceof Rule && $i->w === $a->w) {
                return [$i->x - $a->x, $i->y - $a->y];
            }
            if ($a instanceof Glyph && $i instanceof Glyph && $i->gid === $a->gid) {
                return [$i->x - $a->x, $i->y - $a->y];
            }
        }
        self::fail('cell not found in the grid');
    }

    public function test_column_gap_is_one_em(): void
    {
        $a = Math::row('a');
        $b = Math::row('b');
        $box = self::lay(new Grid([[$a, $b]]));
        $this->assertEqualsWithDelta(self::lay($a, false)->width + self::lay($b, false)->width + 12.0, $box->width, 1e-9);
    }

    public function test_column_width_is_the_widest_cell(): void
    {
        $box = self::lay(new Grid([[Math::row('a')], [Math::row('abcd')]]));
        $this->assertEqualsWithDelta(self::lay(Math::row('abcd'), false)->width, $box->width, 1e-9);
    }

    public function test_cells_are_centered_by_default_and_left_right_on_request(): void
    {
        $i = Math::row('i');
        $w = Math::row('WWWW');
        $wi = self::lay($i, false)->width;
        $ww = self::lay($w, false)->width;
        foreach ([[[], ($ww - $wi) / 2], [['left'], 0.0], [['right'], $ww - $wi]] as [$align, $expect]) {
            $box = self::lay(new Grid([[$i], [$w]], $align));
            $alone = self::glyphs(self::lay($i, false))[0];
            $first = self::glyphs($box)[0];
            $this->assertEqualsWithDelta($expect, $first->x - $alone->x, 1e-9);
        }
    }

    public function test_rows_sit_one_and_a_fifth_em_apart(): void
    {
        $box = self::lay(new Grid([[Math::row('x')], [Math::row('y')]]));
        $g = self::glyphs($box);
        $this->assertEqualsWithDelta(14.4, $g[1]->y - $g[0]->y, 1e-9);
    }

    public function test_tall_content_pushes_rows_apart(): void
    {
        $f1 = Math::frac('a', 'b');
        $f2 = Math::frac('c', 'd');
        $box = self::lay(new Grid([[$f1], [$f2]]));
        $rules = array_values(array_filter($box->items, static fn ($i): bool => $i instanceof Rule));
        // Fraction bars sit at the same height above each cell's baseline.
        $expect = max(self::lay($f1, false)->descent, 0.36 * 12) + max(self::lay($f2, false)->ascent, 0.84 * 12);
        $this->assertGreaterThan(14.4, $expect);
        $this->assertCount(2, $rules);
        $this->assertEqualsWithDelta($expect, $rules[1]->y - $rules[0]->y, 1e-9);
    }

    public function test_grid_is_centered_on_the_axis(): void
    {
        foreach ([1, 2, 3] as $n) {
            $rows = [];
            $frac = Math::frac('a', 'b');
            for ($r = 0; $r < $n; $r++) {
                $rows[] = [Math::row('x'), $frac];
            }
            $box = self::lay(new Grid($rows));
            $this->assertEqualsWithDelta(2 * self::axis(), $box->ascent - $box->descent, 1e-9);
            // The tallest cell of the first row (the fraction) starts exactly at the top of the box.
            $this->assertEqualsWithDelta(-$box->ascent, self::shiftOf($box, $frac)[1] - self::lay($frac, false)->ascent, 1e-9);
        }
    }

    public function test_cells_use_text_style_unless_display(): void
    {
        $f = Math::frac('a', 'b');
        $text = self::lay(new Grid([[$f]]));
        $disp = self::lay(new Grid([[$f]], display: true));
        $this->assertLessThan($disp->height(), $text->height());
    }

    public function test_rows_and_alignments_with_odd_keys_are_read_as_lists(): void
    {
        $odd = new Grid(
            [5 => [3 => Math::row('a'), 9 => Math::row('b')], 'x' => [7 => Math::row('c')]],
            ['p' => 'left', 'q' => 'right'],
        );
        $clean = new Grid([[Math::row('a'), Math::row('b')], [Math::row('c')]], ['left', 'right']);
        $this->assertSame(2, $odd->columns);
        $this->assertSame(['left', 'right'], $odd->columnAlign);
        $this->assertSame([0, 1], array_keys($odd->rows));
        $this->assertSame([0, 1], array_keys($odd->rows[0]));
        $this->assertSame([0, 1], array_keys($odd->rows[1]));
        $this->assertEquals(self::lay($clean), self::lay($odd));
    }

    public function test_a_column_spacing_that_is_not_a_number_is_an_invalid_argument(): void
    {
        foreach ([['wide'], [[1.0]], [null]] as $spacing) {
            try {
                new Grid([[Math::row('a'), Math::row('b')]], columnSpacing: self::untyped($spacing));
                $this->fail('expected an InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Column spacing', $e->getMessage());
            }
        }
    }

    public function test_a_nested_array_alignment_throws_without_a_warning(): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            self::fail("unexpected PHP error: $msg");
        });
        try {
            foreach ([[['left']], ['left', ['x']]] as $align) {
                try {
                    new Grid([[Math::row('a')]], self::untyped($align));
                    $this->fail('expected an InvalidArgumentException');
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('Unknown column alignment', $e->getMessage());
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    private static function untyped(mixed $v): mixed
    {
        return $v;
    }

    public function test_ragged_rows_are_padded(): void
    {
        $g = new Grid([[Math::row('a')], [Math::row('a'), Math::row('b'), Math::row('c')]]);
        $this->assertSame(3, $g->columns);
        $this->assertCount(3, $g->rows[0]);
        $this->assertCount(3, $g->columnAlign);
        $this->assertSame(['center', 'center', 'center'], $g->columnAlign);
        $this->assertSame(['left', 'right', 'right'], (new Grid($g->rows, ['left', 'right']))->columnAlign);
    }

    public function test_row_alignments(): void
    {
        $tall = Math::frac('a', 'b');
        $short = Math::row('x');
        $axis = self::axis();
        $ta = self::lay($tall, false);
        $sa = self::lay($short, false);
        foreach (['baseline', 'axis', 'center', 'top', 'bottom'] as $mode) {
            $box = self::lay(new Grid([[$tall, $short]], [], $mode));
            [, $ty] = self::shiftOf($box, $tall);
            [, $sy] = self::shiftOf($box, $short);
            $tallTop = $ty - $ta->ascent;
            $tallBottom = $ty + $ta->descent;
            $shortTop = $sy - $sa->ascent;
            $shortBottom = $sy + $sa->descent;
            switch ($mode) {
                case 'top':
                    $this->assertEqualsWithDelta($tallTop, $shortTop, 1e-9);
                    break;
                case 'bottom':
                    $this->assertEqualsWithDelta($tallBottom, $shortBottom, 1e-9);
                    break;
                case 'center':
                    $this->assertEqualsWithDelta(($tallTop + $tallBottom) / 2, ($shortTop + $shortBottom) / 2, 1e-9);
                    break;
                case 'baseline':
                    $this->assertEqualsWithDelta($ty, $sy, 1e-9);
                    break;
                case 'axis':
                    // Cell centers sit on the row's axis (axis above the row baseline); the grid then shifts as a whole to center on the axis.
                    $rowA = max($ta->ascent, $sa->ascent);
                    $rowD = max($ta->descent, $sa->descent);
                    $expect = -$axis + (-$axis - ($rowD - $rowA) / 2);
                    $this->assertEqualsWithDelta($expect, ($tallTop + $tallBottom) / 2, 1e-9);
                    $this->assertEqualsWithDelta($expect, ($shortTop + $shortBottom) / 2, 1e-9);
                    break;
            }
        }
    }

    public function test_empty_grid_lays_out_empty(): void
    {
        foreach ([new Grid([]), new Grid([[]])] as $g) {
            $box = self::lay($g);
            $this->assertSame(0.0, $box->width);
            $this->assertSame(0.0, $box->ascent);
            $this->assertSame(0.0, $box->descent);
        }
    }

    public function test_children_are_row_major(): void
    {
        [$a, $b, $c, $d] = [Math::row('a'), Math::row('b'), Math::row('c'), Math::row('d')];
        $this->assertSame([$a, $b, $c, $d], (new Grid([[$a, $b], [$c, $d]]))->children());
    }

    public function test_invalid_alignment_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Grid([[Math::row('a')]], ['middle']);
    }

    public function test_invalid_row_alignment_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Grid([[Math::row('a')]], [], 'middle');
    }

    public function test_nested_grid_in_a_cell_and_in_scripts(): void
    {
        $inner = new Grid([[Math::row('a'), Math::row('b')], [Math::row('c'), Math::row('d')]]);
        $this->assertGreaterThan(0.0, self::lay(new Grid([[$inner, Math::row('x')]]))->width);
        $this->assertGreaterThan(0.0, self::lay(new Scripts($inner, null, Math::row('2')))->width);
    }

    public function test_a_60_by_60_grid_lays_out_fast(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $row = [];
            for ($j = 0; $j < 60; $j++) {
                $row[] = Math::row('a');
            }
            $rows[] = $row;
        }
        memory_reset_peak_usage();
        $mem = memory_get_peak_usage();
        $t = microtime(true);
        $box = self::lay(new Grid($rows));
        $this->assertLessThan(2.0, microtime(true) - $t);
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $mem);
        $this->assertGreaterThan(0.0, $box->width);
    }

    public function test_column_spacing_sets_gaps(): void
    {
        $grid = new Grid([[Math::row('a'), Math::row('b'), Math::row('c')]], ['left'], columnSpacing: [0.0, 2.0]);
        self::assertSame([0.0, 2.0], $grid->columnSpacing);
        $xs = array_map(static fn (Glyph $g): float => $g->x, self::glyphs(self::lay($grid)));
        $w = static fn (string $s): float => self::lay(Math::row($s))->width;
        self::assertEqualsWithDelta($w('a'), $xs[1] - $xs[0], 0.01);
        self::assertEqualsWithDelta($w('b') + 2 * self::SIZE, $xs[2] - $xs[1], 0.01);
    }

    public function test_column_spacing_repeats_and_defaults(): void
    {
        self::assertSame([1.0, 1.0], (new Grid([[Math::row('a'), Math::row('b'), Math::row('c')]]))->columnSpacing);
        self::assertSame([0.5, 0.5], (new Grid([[Math::row('a'), Math::row('b'), Math::row('c')]], columnSpacing: [0.5]))->columnSpacing);
        self::assertSame([], (new Grid([[Math::row('a')]], columnSpacing: [3.0]))->columnSpacing);
    }

    public function test_negative_column_spacing_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Grid([[Math::row('a'), Math::row('b')]], columnSpacing: [-0.1]);
    }

    private static function height(string $tex): float
    {
        $box = Math::layout(\Phactorial\Tex\TexReader::read($tex)->node, 18.0, true);

        return $box->ascent + $box->descent;
    }

    public function test_a_matrix_row_has_the_array_strut(): void
    {
        self::assertEqualsWithDelta(2.4 * 18, self::height('\begin{matrix} a & b \\\\ c & d \end{matrix}'), 1e-6);
        self::assertEqualsWithDelta(2.4 * 18, self::height('\begin{matrix} a & \\\\ & d \end{matrix}'), 1e-6);
    }

    public function test_an_alignment_adds_jot_between_rows(): void
    {
        self::assertEqualsWithDelta(2.7 * 18, self::height('\begin{aligned} a &= b \\\\ c &= d \end{aligned}'), 1e-6);
    }

    public function test_tall_content_grows_its_row(): void
    {
        self::assertGreaterThan(self::height('\begin{matrix} a \\\\ c \end{matrix}') + 0.2 * 18, self::height('\begin{matrix} \frac{a}{b} \\\\ c \end{matrix}'));
    }

    public function test_row_spacing_is_drawn(): void
    {
        $base = self::height('\begin{matrix} a \\\\ b \end{matrix}');
        self::assertEqualsWithDelta($base + 18, self::height('\begin{matrix} a \\\\[1em] b \end{matrix}'), 1e-6);
        self::assertEqualsWithDelta($base + 18, self::height('\begin{matrix} a \\\\[10pt] b \end{matrix}'), 1e-6);
        self::assertEqualsWithDelta($base + 18, self::height('\begin{matrix} a \\\\[18mu] b \end{matrix}'), 1e-6);
        self::assertEqualsWithDelta($base - 0.2 * 18, self::height('\begin{matrix} a \\\\[-0.2em] b \end{matrix}'), 1e-6);
        self::assertEqualsWithDelta($base + 18, self::height('\begin{matrix} a \\\\[1em] b \\\\ \end{matrix}'), 1e-6);
    }

    public function test_row_spacing_is_normalized_and_must_be_finite(): void
    {
        $rows = [[Math::row('a')], [Math::row('b')], [Math::row('c')]];
        self::assertSame([0.0, 0.0], (new Grid($rows))->rowSpacing);
        self::assertSame([0.5, 0.0], (new Grid($rows, rowSpacing: [0.5]))->rowSpacing);
        self::assertSame([0.5, -1.0], (new Grid($rows, rowSpacing: [0.5, -1.0, 7.0]))->rowSpacing);
        self::assertSame([], (new Grid([[Math::row('a')]], rowSpacing: [1.0]))->rowSpacing);
        foreach ([NAN, INF, -INF] as $bad) {
            try {
                new Grid($rows, rowSpacing: [$bad]);
                self::fail('expected an InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Row spacing', $e->getMessage());
            }
        }
    }

    private static function boxOf(string $tex): Box
    {
        return Math::layout(\Phactorial\Tex\TexReader::read($tex)->node, 18.0, true);
    }

    public function test_a_large_negative_row_gap_keeps_the_box_over_the_ink(): void
    {
        $box = self::boxOf('\begin{matrix} a \\\\[-5em] b \end{matrix}');
        self::assertGreaterThan(0.0, $box->ascent + $box->descent);
        $glyphs = self::glyphs($box);
        self::assertCount(2, $glyphs);
        foreach ($glyphs as $g) {
            self::assertGreaterThanOrEqual(-$box->ascent, $g->y);
            self::assertLessThanOrEqual($box->descent, $g->y);
        }
        $svg = (new \Phactorial\Render\SvgRenderer(Math::stixTwoMath()))->render($box);
        self::assertSame(1, preg_match('/height="([\d.]+)"/', $svg));
    }

    public function test_gathered_and_a_top_level_break_have_the_jot(): void
    {
        self::assertEqualsWithDelta(2.7 * 18, self::height('\begin{gathered} a \\\\ b \end{gathered}'), 1e-6);
        self::assertEqualsWithDelta(2.7 * 18, self::height('a \\\\ b'), 1e-6);
        self::assertEqualsWithDelta(2.7 * 18 + 18, self::height('\begin{gathered} a \\\\[1em] b \end{gathered}'), 1e-6);
    }

    public function test_the_jot_survives_mathml(): void
    {
        foreach (['\left( \begin{aligned} a&=b \\\\ c&=d \end{aligned} \right)', '\begin{aligned} a&=b \\\\[1em] c&=d \end{aligned}', '\left( \begin{gathered} a \\\\[2pt] b \end{gathered} \right)'] as $tex) {
            $node = \Phactorial\Tex\TexReader::read($tex)->node;
            $a = Math::layout($node, 18.0, true);
            $b = Math::layout(\Phactorial\Mathml\MathmlReader::read(\Phactorial\Render\MathmlWriter::write($node))->node, 18.0, true);
            self::assertEqualsWithDelta($a->width, $b->width, 0.05, $tex);
            self::assertEqualsWithDelta($a->ascent + $a->descent, $b->ascent + $b->descent, 0.05, $tex);
            $c = Math::layout(\Phactorial\Tex\TexReader::read(\Phactorial\Render\TexWriter::write($node))->node, 18.0, true);
            self::assertEqualsWithDelta($a->width, $c->width, 0.05, $tex);
            self::assertEqualsWithDelta($a->ascent + $a->descent, $c->ascent + $c->descent, 0.05, $tex);
        }
    }

    public function test_an_external_mtable_rowspacing_is_drawn(): void
    {
        $h = static fn (string $attr): float => ($b = Math::layout(\Phactorial\Mathml\MathmlReader::read('<math><mtable' . $attr . '><mtr><mtd><mi>a</mi></mtd></mtr><mtr><mtd><mi>b</mi></mtd></mtr><mtr><mtd><mi>c</mi></mtd></mtr></mtable></math>')->node, 18.0, true))->ascent + $b->descent;
        // MathML's rowspacing is the whole gap and defaults to 1ex (0.5em): that default is the strut look.
        self::assertEqualsWithDelta($h(''), $h(' rowspacing="1ex"'), 1e-6);
        self::assertEqualsWithDelta($h('') + 2 * 0.5 * 18, $h(' rowspacing="2ex"'), 1e-6);
        self::assertEqualsWithDelta($h('') + 2 * 0.5 * 18, $h(' rowspacing="1em"'), 1e-6);
        self::assertEqualsWithDelta($h('') + 0.5 * 18 + 0.2 * 18, $h(' rowspacing="1em 0.7em"'), 1e-6);
        self::assertEqualsWithDelta($h('') - 2 * 0.1 * 18, $h(' rowspacing="0.4em"'), 1e-6);
        $notes = \Phactorial\Mathml\MathmlReader::read('<math><mtable rowspacing="1em"><mtr><mtd><mi>a</mi></mtd></mtr></mtable></math>')->notes();
        self::assertSame([], $notes);
        $bad = \Phactorial\Mathml\MathmlReader::read('<math><mtable rowspacing="wide"><mtr><mtd><mi>a</mi></mtd></mtr></mtable></math>')->notes();
        self::assertSame(['rowspacing is not drawn'], array_map(static fn ($n) => $n->reason, $bad));
    }

    public function test_mathml_row_spacing_writes_and_reads_the_same_gaps(): void
    {
        $grid = new Grid([[Math::row('a')], [Math::row('b')], [Math::row('c')]], rowSpacing: [0.3, -0.25]);
        $back = \Phactorial\Mathml\MathmlReader::read(\Phactorial\Render\MathmlWriter::write($grid))->node;
        self::assertInstanceOf(Grid::class, $back);
        self::assertEqualsWithDelta([0.3, -0.25], $back->rowSpacing, 1e-9);
        self::assertStringNotContainsString('rowspacing', \Phactorial\Render\MathmlWriter::write(new Grid([[Math::row('a')], [Math::row('b')]])));
    }

    public function test_script_grids_keep_the_old_row_rule(): void
    {
        $b = self::boxOf('\sum_{\substack{i<n\\\\j<m}} x');
        self::assertEqualsWithDelta(16.569, $b->ascent, 0.01);
        self::assertEqualsWithDelta(36.738, $b->descent, 0.01);
        $b = self::boxOf('x^{\begin{subarray}{l} a\\\\b \end{subarray}}');
        self::assertEqualsWithDelta(23.958, $b->ascent, 0.01);
        self::assertEqualsWithDelta(0.18, $b->descent, 0.01);
        // Row spacing still adds there.
        $c = self::boxOf('x^{\begin{subarray}{l} a\\\\[1em] b \end{subarray}}');
        self::assertGreaterThan($b->ascent + 5.0, $c->ascent);
    }

    public function test_a_malformed_dimension_changes_nothing(): void
    {
        $plain = self::height('\begin{matrix} a \\\\ b \end{matrix}');
        foreach (['3zz', '1.5.3pt', '2.5.pt'] as $d) {
            $tex = '\begin{matrix} a \\\\[' . $d . '] b \end{matrix}';
            self::assertEqualsWithDelta($plain, self::height($tex), 1e-6, $d);
            $notes = \Phactorial\Tex\TexReader::read($tex)->notes();
            self::assertSame(['row spacing is not drawn'], array_map(static fn ($n) => $n->reason, $notes), $d);
        }
    }

    public function test_a_trailing_or_leading_dot_dimension_is_tex(): void
    {
        $plain = self::height('\\begin{matrix} a \\\\ b \\end{matrix}');
        self::assertEqualsWithDelta($plain + 0.2 * 18, self::height('\\begin{matrix} a \\\\[2.pt] b \\end{matrix}'), 1e-6);
        self::assertEqualsWithDelta($plain + 0.5 * 18, self::height('\\begin{matrix} a \\\\[.5em] b \\end{matrix}'), 1e-6);
        self::assertSame([], \Phactorial\Tex\TexReader::read('\\begin{matrix} a \\\\[2.pt] b \\end{matrix}')->notes());
    }

    public function test_a_dot_dimension_in_a_space_command_is_tex(): void
    {
        $w = static fn (string $tex): float => self::boxOf('a' . $tex . 'b')->width;
        self::assertEqualsWithDelta($w('\\hspace{0.2em}'), $w('\\hspace{2.pt}'), 1e-9);
        self::assertEqualsWithDelta($w('\\kern0.2em'), $w('\\kern2.pt'), 1e-9);
    }
}
