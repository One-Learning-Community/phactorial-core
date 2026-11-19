<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;

/**
 * An equation grid (mtable): rows of cells in aligned columns, laid out the way TeX sets a matrix.
 * Columns are one em apart unless columnSpacing says otherwise, every row is at least as tall as TeX's array strut (0.84 em above the baseline, 0.36 em below) and rows stack with no gap beyond it, rowSpacing adds more after a row (the reader puts TeX's 0.3 em jot of aligned and gathered there), rows may overlap when it is negative, a script-size grid has no strut and keeps rows 1.2 em apart, and the whole grid is centered on the math axis.
 */
final class Grid extends Node
{
    /** @internal Engine detail, not public API. TeX's \jot in em: the extra space between the lines of aligned and gathered. */
    public const JOT = 0.3;
    /** @internal Engine detail, not public API. MathML's default rowspacing, 1ex, in em (the MathML reader takes an ex as half an em). */
    public const MATHML_ROW_SPACING = 0.5;
    /** @internal Engine detail, not public API. */
    public const COLUMN_ALIGN = ['left', 'center', 'right'];
    /** @internal Engine detail, not public API. */
    public const ROW_ALIGN = ['baseline', 'axis', 'center', 'top', 'bottom'];

    /** @var list<list<Node>> every row padded to the widest */
    public readonly array $rows;
    /** @var list<string> one entry per column */
    public readonly array $columnAlign;
    /** @var list<float> em between each pair of adjacent columns: one entry per gap */
    public readonly array $columnSpacing;
    /** @var list<float> extra em after each row, as `\\[len]` gives: one entry per gap between rows, may be negative */
    public readonly array $rowSpacing;
    public readonly int $columns;

    /**
     * @param array<array<Node>> $rows read in order, whatever their keys
     * @param array<string> $columnAlign read in order, whatever their keys; left, center or right per column; the last repeats, empty means center
     * @param string $rowAlign how cells of one row line up: baseline, axis, center, top or bottom
     * @param bool $display lay the cells out in display style rather than text style
     * @param array<float> $columnSpacing em between adjacent columns, read in order; the last repeats, empty means 1.0
     * @param bool $alignment the columns are alignment points (aligned/align): writers that can say so use an equation array
     * @param array<float> $rowSpacing extra em after each row, read in order; one entry per gap between rows, missing ones are 0 and extra ones are dropped
     *
     * @throws \InvalidArgumentException for an unknown alignment or a negative or non-finite column spacing or a non-finite row spacing
     *
     * @internal Engine detail, not public API.
     */
    public function __construct(
        array $rows,
        array $columnAlign = [],
        public readonly string $rowAlign = 'baseline',
        public readonly bool $display = false,
        array $columnSpacing = [],
        public readonly bool $alignment = false,
        array $rowSpacing = [],
    ) {
        foreach ($rowSpacing as $g) {
            // @phpstan-ignore function.alreadyNarrowedType
            if (! in_array(get_debug_type($g), ['float', 'int'], true) || ! is_finite($g)) {
                throw new \InvalidArgumentException('Row spacing must be a list of finite em values.');
            }
        }
        foreach ($columnSpacing as $g) {
            // The docblock says float; callers without static analysis can still pass anything (a string would be a TypeError).
            // @phpstan-ignore function.alreadyNarrowedType
            if (! in_array(get_debug_type($g), ['float', 'int'], true) || ! is_finite($g) || $g < 0) {
                throw new \InvalidArgumentException('Column spacing must be a list of finite, non-negative em values.');
            }
        }
        foreach ($columnAlign as $a) {
            if (! in_array($a, self::COLUMN_ALIGN, true)) {
                // The docblock says string; callers without static analysis can still pass anything.
                // @phpstan-ignore function.alreadyNarrowedType
                throw new \InvalidArgumentException(sprintf('Unknown column alignment "%s"; use one of: %s', is_string($a) ? $a : get_debug_type($a), implode(', ', self::COLUMN_ALIGN)));
            }
        }
        if (! in_array($rowAlign, self::ROW_ALIGN, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown row alignment "%s"; use one of: %s', $rowAlign, implode(', ', self::ROW_ALIGN)));
        }
        $columnAlign = array_values($columnAlign);
        $rows = array_map(array_values(...), array_values($rows));
        $columns = 0;
        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }
        $padded = [];
        foreach ($rows as $row) {
            while (count($row) < $columns) {
                $row[] = new Row([]);
            }
            $padded[] = $row;
        }
        $align = [];
        $last = $columnAlign === [] ? 'center' : $columnAlign[array_key_last($columnAlign)];
        for ($j = 0; $j < $columns; $j++) {
            $align[] = $columnAlign[$j] ?? $last;
        }
        $columnSpacing = array_map(floatval(...), array_values($columnSpacing));
        $gaps = [];
        for ($j = 0; $j < $columns - 1; $j++) {
            $gaps[] = $columnSpacing[$j] ?? ($columnSpacing === [] ? 1.0 : $columnSpacing[array_key_last($columnSpacing)]);
        }
        $this->columnSpacing = $gaps;
        $rowSpacing = array_map(floatval(...), array_values($rowSpacing));
        $between = [];
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $between[] = $rowSpacing[$i] ?? 0.0;
        }
        $this->rowSpacing = $between;
        $this->rows = $padded;
        $this->columnAlign = $align;
        $this->columns = $columns;
    }

    /**
     * The same grid with other row spacing.
     *
     * @param array<float> $rowSpacing as the constructor reads it
     *
     * @throws \InvalidArgumentException for a non-finite spacing
     *
     * @internal Engine detail, not public API.
     */
    public function withRowSpacing(array $rowSpacing): self
    {
        return new self($this->rows, $this->columnAlign, $this->rowAlign, $this->display, $this->columnSpacing, $this->alignment, $rowSpacing);
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            foreach ($row as $cell) {
                $out[] = $cell;
            }
        }

        return $out;
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        if ($this->columns === 0) {
            return new Box();
        }
        $cc = $c->withDisplay($this->display);
        $em = $c->size();
        $axis = $c->k('axisHeight');

        $strut = $c->level === 0;
        $boxes = [];
        $colW = array_fill(0, $this->columns, 0.0);
        $rowA = [];
        $rowD = [];
        foreach ($this->rows as $i => $row) {
            // TeX's \@arstrut: 0.7 and 0.3 of a 1.2 em baseline skip, in every row. Scripts (\substack, subarray) have none.
            $rowA[$i] = $strut ? 0.84 * $em : 0.0;
            $rowD[$i] = $strut ? 0.36 * $em : 0.0;
            foreach ($row as $j => $cell) {
                $b = $cell->layout($cc);
                $boxes[$i][$j] = $b;
                $colW[$j] = max($colW[$j], $b->width);
                $rowA[$i] = max($rowA[$i], $b->ascent);
                $rowD[$i] = max($rowD[$i], $b->descent);
            }
        }

        $colX = [];
        $x = 0.0;
        foreach ($colW as $j => $w) {
            $colX[$j] = $x;
            $x += $w + ($this->columnSpacing[$j] ?? 0.0) * $em;
        }
        $width = $x;

        $base = [];
        $y = 0.0;
        foreach ($this->rows as $i => $_) {
            if ($i > 0) {
                $stack = $rowD[$i - 1] + $rowA[$i];
                $y += ($strut ? $stack : max(1.2 * $em, $stack + 0.1 * $em)) + $this->rowSpacing[$i - 1] * $em;
            }
            $base[$i] = $y;
        }
        // Rows may overlap (a negative row spacing): the box covers the ink of all of them.
        $top = 0.0;
        $bottom = 0.0;
        foreach ($this->rows as $i => $_) {
            $top = $i === 0 ? $base[0] - $rowA[0] : min($top, $base[$i] - $rowA[$i]);
            $bottom = $i === 0 ? $base[0] + $rowD[0] : max($bottom, $base[$i] + $rowD[$i]);
        }
        $shift = -$axis - ($top + $bottom) / 2;

        $out = new Box();
        foreach ($this->rows as $i => $row) {
            foreach ($row as $j => $_) {
                $b = $boxes[$i][$j];
                $dx = $colX[$j] + match ($this->columnAlign[$j]) {
                    'left' => 0.0,
                    'right' => $colW[$j] - $b->width,
                    default => ($colW[$j] - $b->width) / 2,
                };
                $by = match ($this->rowAlign) {
                    'top' => $base[$i] - $rowA[$i] + $b->ascent,
                    'bottom' => $base[$i] + $rowD[$i] - $b->descent,
                    'center' => $base[$i] + (($rowD[$i] - $rowA[$i]) - ($b->descent - $b->ascent)) / 2,
                    'axis' => $base[$i] - $axis + ($b->ascent - $b->descent) / 2,
                    default => $base[$i],
                };
                $out->place($b, $dx, $by + $shift);
            }
        }
        $h = $bottom - $top;
        $out->width = $width;
        $out->ascent = $h / 2 + $axis;
        $out->descent = $h / 2 - $axis;

        return $out;
    }
}
