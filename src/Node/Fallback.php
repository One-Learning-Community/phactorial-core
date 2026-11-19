<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;
use Phactorial\MathNote;

/** Content a reader could not render faithfully: what stands in for it (or nothing) and the note saying why. */
final class Fallback extends Node
{
    /** @internal Engine detail, not public API. */
    public function __construct(public readonly ?Node $content, public readonly MathNote $note)
    {
    }

    /**
     * Sees through what only annotates a node (a Fallback with content, a DisplayStyle, a TexSource, a Color), so checks for an Operator or
     * a Fraction find it.
     *
     * @internal Engine detail, not public API.
     */
    public static function unwrap(Node $n): Node
    {
        while (true) {
            if ($n instanceof self && $n->content !== null) {
                $n = $n->content;
            } elseif ($n instanceof DisplayStyle || $n instanceof TexSource || $n instanceof Color) {
                $n = $n->content;
            } else {
                return $n;
            }
        }
    }

    /**
     * The node with $f applied to what unwrap() would reach (the innermost node past notes, display switches, source and
     * color), each wrapper put back around the result.
     *
     * @param \Closure(Node): Node $f
     *
     * @internal Engine detail, not public API.
     */
    public static function mapInner(Node $n, \Closure $f): Node
    {
        return match (true) {
            $n instanceof self && $n->content !== null => new self(self::mapInner($n->content, $f), $n->note),
            $n instanceof DisplayStyle => new DisplayStyle(self::mapInner($n->content, $f), $n->display),
            $n instanceof TexSource => new TexSource(self::mapInner($n->content, $f), $n->tex, $n->close),
            $n instanceof Color => new Color(self::mapInner($n->content, $f), $n->color, $n->inline),
            default => $f($n),
        };
    }

    /**
     * The color of the innermost Color wrapper unwrap() peels, or null when it peels none.
     *
     * @internal Engine detail, not public API.
     */
    public static function color(Node $n): ?string
    {
        $color = null;
        while (true) {
            if ($n instanceof self && $n->content !== null) {
                $n = $n->content;
            } elseif ($n instanceof DisplayStyle || $n instanceof TexSource) {
                $n = $n->content;
            } elseif ($n instanceof Color) {
                $color = $n->color;
                $n = $n->content;
            } else {
                return $color;
            }
        }
    }

    /**
     * The display switches of the DisplayStyle wrappers unwrap() peels, outermost first.
     *
     * @return list<bool>
     *
     * @internal Engine detail, not public API.
     */
    public static function displays(Node $n): array
    {
        $out = [];
        while (true) {
            if ($n instanceof self && $n->content !== null) {
                $n = $n->content;
            } elseif ($n instanceof TexSource || $n instanceof Color) {
                $n = $n->content;
            } elseif ($n instanceof DisplayStyle) {
                $out[] = $n->display;
                $n = $n->content;
            } else {
                return $out;
            }
        }
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->content === null ? [] : [$this->content];
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        return $this->content === null ? new Box() : $this->content->layout($c);
    }
}
