<?php

declare(strict_types=1);

namespace Phactorial\Layout;

use Phactorial\Colors;

/**
 * A glyph placed by its origin (x, y), y being the baseline position, y down.
 * $text is the character drawn, for selection and ToUnicode: for a letter set in mathematical italic it is the italic code point,
 * not the letter typed; null for size-variant and assembly pieces
 * that stand for part of a larger symbol. $size is the font size in points.
 * $sx and $sy scale the outline along x and y about the glyph origin (1.0 unless a stretch assembly draws a run of one straight extender once).
 * $color is the fill as `#rrggbb` (lowercase), or null for the drawing's default ink.
 */
final readonly class Glyph implements DisplayItem
{
    /** @internal Engine detail, not public API. */
    public function __construct(
        public int $gid,
        public ?string $text,
        public float $x,
        public float $y,
        public float $size,
        public float $sx = 1.0,
        public float $sy = 1.0,
        public ?string $color = null,
    ) {
    }

    /** This item moved by $dx and $dy points, as a new item; the original is unchanged. */
    public function translated(float $dx, float $dy): static
    {
        return new self($this->gid, $this->text, $this->x + $dx, $this->y + $dy, $this->size, $this->sx, $this->sy, $this->color);
    }

    /** This glyph with the fill $color (`#rrggbb`), as a new item; the original is unchanged. Throws InvalidArgumentException unless $color is lowercase `#rrggbb`. */
    public function withColor(string $color): static
    {
        if (! Colors::isHex($color)) {
            throw new \InvalidArgumentException('A color is `#rrggbb` in lowercase hex.');
        }

        return new self($this->gid, $this->text, $this->x, $this->y, $this->size, $this->sx, $this->sy, $color);
    }
}
