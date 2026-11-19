<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Colors;
use Phactorial\Layout\Box;
use Phactorial\Layout\Context;

/**
 * Color on part of a formula: $color is `#rrggbb` (lowercase) and fills every glyph and rule of $content.
 * $inline is true when the source gave the color as a command that applies to the rest of its group
 * (TeX `\color`) and false when it wrapped a single argument (`\textcolor`, `mathcolor`); writers use it to round-trip.
 * It also drives layout: a Row spreads the content of an inline color that is an unbraced TeX switch into its own
 * items (see Row::items()), so the operators inside space against their neighbors as if the color were absent.
 * A switch that opens a group (`{\color{red} x}`) is an atom and does not spread.
 */
final class Color extends Node
{
    /** @internal Engine detail, not public API. */
    public function __construct(public readonly Node $content, public readonly string $color, public readonly bool $inline = false)
    {
        if (! Colors::isHex($color)) {
            throw new \InvalidArgumentException('A color is `#rrggbb` in lowercase hex.');
        }
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return [$this->content];
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        return self::box($this->content->layout($c), $this->color);
    }

    /**
     * A box with $inner's metrics and kern fields that draws $inner in $color.
     *
     * @internal Engine detail, not public API.
     */
    public static function box(Box $inner, string $color): Box
    {
        $box = new Box($inner->width, $inner->ascent, $inner->descent, $inner->italic, $inner->simple);
        $box->firstGid = $inner->firstGid;
        $box->lastGid = $inner->lastGid;
        $box->lastAscent = $inner->lastAscent;
        $box->lastDescent = $inner->lastDescent;
        $box->trail = $inner->trail;
        $box->color = $color;
        $box->place($inner, 0.0, 0.0);

        return $box;
    }
}
