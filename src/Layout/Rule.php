<?php

declare(strict_types=1);

namespace Phactorial\Layout;

use Phactorial\Colors;

/** A filled rectangle (fraction bar, radical overbar). (x, y) is its top-left corner, y down. $color is the fill as `#rrggbb` (lowercase), or null for the drawing's default ink. */
final readonly class Rule implements DisplayItem
{
    /** @internal Engine detail, not public API. */
    public function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
        public ?string $color = null,
    ) {
    }

    /** This item moved by $dx and $dy points, as a new item; the original is unchanged. */
    public function translated(float $dx, float $dy): static
    {
        return new self($this->x + $dx, $this->y + $dy, $this->w, $this->h, $this->color);
    }

    /** This rule with the fill $color (`#rrggbb`), as a new item; the original is unchanged. Throws InvalidArgumentException unless $color is lowercase `#rrggbb`. */
    public function withColor(string $color): static
    {
        if (! Colors::isHex($color)) {
            throw new \InvalidArgumentException('A color is `#rrggbb` in lowercase hex.');
        }

        return new self($this->x, $this->y, $this->w, $this->h, $color);
    }
}
