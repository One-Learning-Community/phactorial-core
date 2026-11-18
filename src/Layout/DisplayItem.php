<?php

declare(strict_types=1);

namespace Phactorial\Layout;

/**
 * One drawing primitive. Coordinates are points, origin at the formula's left edge on its baseline, y grows DOWN.
 *
 * Sealed: the library's own types only. Extending or implementing it is unsupported, and through 1.x, layout yields only Glyph and Rule unless the caller opts in to more through `Math::layout()`.
 */
interface DisplayItem
{
    /** This item moved by $dx and $dy points, as a new item; the original is unchanged. */
    public function translated(float $dx, float $dy): static;
}
