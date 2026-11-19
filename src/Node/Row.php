<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;
use Phactorial\OperatorDictionary;

/** mrow: children side by side on a shared baseline. Operator forms come from Forms::of(). */
final class Row extends Node
{
    /**
     * @param list<Node> $children
     *
     * @internal Engine detail, not public API.
     */
    public function __construct(
        /** @var list<Node> */
        public readonly array $children,
    ) {
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->children;
    }

    /** @var ?list<array{Node, ?string}> */
    private ?array $items = null;

    private ?bool $splices = null;

    /** Whether the row is the content of a fence, so a relation first or last in it drops its space against the delimiter. */
    private bool $fenceOpen = false;

    private bool $fenceClose = false;

    /**
     * A copy that knows it sits inside a fence (see Forms::drops()); the row itself is unchanged.
     *
     * @internal Engine detail, not public API.
     */
    public function inFence(bool $open, bool $close): self
    {
        $copy = clone $this;
        $copy->fenceOpen = $open;
        $copy->fenceClose = $close;

        return $copy;
    }

    /** Whether any child is an inline color to splice; when none is, layout reads $children directly. */
    private function splices(): bool
    {
        if ($this->splices === null) {
            $this->splices = false;
            foreach ($this->children as $child) {
                if (self::inlineRow($child) !== null) {
                    $this->splices = true;

                    break;
                }
            }
        }

        return $this->splices;
    }

    /**
     * The children as laid out, each paired with the innermost inline color around it. An inline Color child (the TeX
     * switch form, seen through TexSource and contentful Fallback) whose content is a Row is replaced by that row's own
     * items, so its operators space against the neighbors as if the color were absent. children() is unchanged.
     *
     * @return list<array{Node, ?string}>
     *
     * @internal Engine detail, not public API.
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $out = [];
        foreach ($this->children as $child) {
            $inner = self::inlineRow($child);
            if ($inner === null) {
                $out[] = [$child, null];

                continue;
            }
            [$row, $color] = $inner;
            foreach ($row->items() as [$node, $own]) {
                $out[] = [$node, $own ?? $color];
            }
        }

        return $this->items = $out;
    }

    /**
     * @return ?array{Row, ?string} the Row an unbraced color switch spreads into its parent and its color (null for a
     *                              rejected switch, which colors nothing), or null when the child is no such thing
     */
    private static function inlineRow(Node $n): ?array
    {
        $switch = false;
        while (($n instanceof TexSource) || ($n instanceof Fallback && $n->content !== null)) {
            if ($n instanceof TexSource) {
                // A braced group is an atom, as in TeX: only a switch without a brace of its own spreads into its row.
                if ($n->isBracedColorSwitch()) {
                    return null;
                }
                $switch = $switch || $n->isColorSwitch();
            }
            $n = $n->content;
        }
        $color = null;
        if ($n instanceof Color) {
            if (! $n->inline) {
                return null;
            }
            $color = $n->color;
            $n = $n->content;
            while (($n instanceof TexSource) || ($n instanceof Fallback && $n->content !== null)) {
                $n = $n->content;
            }
        } elseif (! $switch) {
            return null;
        }

        return $n instanceof self ? [$n, $color] : null;
    }

    /**
     * The nodes of items() without their colors: $children itself when nothing splices. Writers index this, and forms().
     *
     * @return list<Node>
     *
     * @internal Engine detail, not public API.
     */
    public function nodes(): array
    {
        return $this->splices() ? array_column($this->items(), 0) : $this->children;
    }

    /**
     * @param list<?string> $forms from forms()
     * @param list<Node>|null $nodes the items to judge in place of nodes(), as forms() of them
     *
     * @return list<array{bool, bool}> per item: whether its lspace and rspace are dropped (see Forms::drops())
     *
     * @internal Engine detail, not public API.
     */
    public function drops(array $forms, ?array $nodes = null): array
    {
        return Forms::drops($nodes ?? $this->nodes(), $forms, $this->fenceOpen, $this->fenceClose);
    }

    /**
     * @return list<?string> operator form of each item (see items()): prefix, infix, postfix, or null for non-operators
     *
     * @internal Engine detail, not public API.
     */
    public function forms(): array
    {
        return Forms::of($this->nodes());
    }

    /** An operator with the thick space of a relation before it (TeX: Op-Rel is thick only, no thin on top). */
    private static function isRelation(Operator $o, ?string $form): bool
    {
        $l = $o->lspace !== null ? $o->lspace * 18 : OperatorDictionary::lookup($o->char, $form ?? OperatorDictionary::FORM_INFIX)['lspace'];

        return $l >= 5 - 1e-6;
    }

    /** A function name (sin, log, \operatorname{foo}), alone or with scripts: TeX's Op class. */
    private static function isFunctionName(?Node $n): bool
    {
        $n = $n === null ? null : Fallback::unwrap($n);
        if ($n instanceof Scripts) {
            return self::isFunctionName($n->base);
        }

        return $n instanceof Identifier && $n->function && $n->upright && mb_strlen($n->name) > 1;
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        $boxes = null;

        return $this->build($c, [], $boxes);
    }

    /**
     * The row laid out, with the box of every item (see items()) in `$boxes` and, for an item index in `$reuse`, that box in place of
     * laying the child out again (a fence re-places its Middles at their stretched size without redoing the rest).
     *
     * @param array<int, Box> $reuse
     * @param ?array<int, Box> $boxes filled when given as an array, left alone when null
     *
     * @internal Engine detail, not public API.
     */
    public function build(Context $c, array $reuse, ?array &$boxes): Box
    {
        $out = new Box();
        $x = 0.0;
        $prev = null;
        $last = null;
        $forms = $this->forms();
        $drops = $this->drops($forms);
        $items = $this->splices() ? $this->items() : null;
        for ($i = 0, $n = $items === null ? count($this->children) : count($items); $i < $n; $i++) {
            if ($items === null) {
                $child = $this->children[$i];
                $inline = null;
            } else {
                [$child, $inline] = $items[$i];
            }
            // A fraction next to an operand (mixed number "3 1/2", "2 x/y") gets a thin space so it does not read as one blob.
            $seen = Fallback::unwrap($child);
            // A Middle spaces itself like an operator.
            if ($prev !== null && ! ($prev instanceof Operator) && ! ($prev instanceof Middle) && ! ($prev instanceof Space) && ! ($seen instanceof Space)) {
                if (! ($seen instanceof Operator) && ! ($seen instanceof Middle) && ($seen instanceof Fraction || $prev instanceof Fraction)) {
                    $x += $c->mu(3);
                }
            }
            // TeX's table: a function name (Op) takes a thin space after an ordinary or a closing delimiter, and none after an
            // opening one or at the start. Its own trailing space is dropped before an opening or closing delimiter or a comma.
            $isName = self::isFunctionName($seen);
            if ($prev !== null && $isName && ! self::isFunctionName($prev) && ! ($prev instanceof Space) && ! ($prev instanceof Fraction) && ! ($seen instanceof Space)
                && (! ($prev instanceof Operator || $prev instanceof Middle) || ($prev instanceof Operator && (OperatorDictionary::isClosing($prev->char) || $prev->char === '!')))) {
                $x += $c->mu(3);
            }
            if ($last !== null && $last->trail > 0.0 && self::isFunctionName($prev) && $seen instanceof Operator && (OperatorDictionary::isOpening($seen->char) || OperatorDictionary::isClosing($seen->char) || in_array($seen->char, [',', ';'], true) || self::isRelation($seen, $forms[$i]))) {
                $x -= $last->trail;
            }
            $form = $forms[$i];
            // Forms::of gives every operator a form and no one else.
            $ctx = $c;
            foreach (Fallback::displays($child) as $display) {
                $ctx = $ctx->withDisplay($display);
            }
            $b = $reuse[$i] ?? ($seen instanceof Operator ? Forms::withDrops($seen, ...$drops[$i])->layoutAs($ctx, $form ?? throw new \LogicException('Forms::of gave an operator no form')) : $child->layout($c));
            // layoutAs() bypasses the wrappers, so a color around the operator is applied here.
            if (! isset($reuse[$i]) && $seen instanceof Operator && ($color = Fallback::color($child)) !== null) {
                $b = Color::box($b, $color);
            }
            if (! isset($reuse[$i]) && $inline !== null) {
                $b = Color::box($b, $inline);
            }
            if ($boxes !== null) {
                $boxes[$i] = $b;
            }
            $out->place($b, $x, 0.0);
            $x += $b->width;
            $out->ascent = max($out->ascent, $b->ascent);
            $out->descent = max($out->descent, $b->descent);
            $prev = $seen;
            if ($i === 0) {
                $out->firstGid = $b->firstGid;
            }
            $last = $b;
        }
        $out->width = $x;
        if ($last !== null) {
            $out->italic = $last->italic;
            $out->trail = $last->trail;
            $out->lastGid = $last->lastGid;
            $out->lastAscent = $last->lastAscent ?? $last->ascent;
            $out->lastDescent = $last->lastDescent ?? $last->descent;
            $out->simple = ($items === null ? count($this->children) : count($items)) === 1 && $last->simple;
        }

        return $out;
    }
}
