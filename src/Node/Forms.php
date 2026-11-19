<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\OperatorDictionary;

/**
 * Operator forms by position. An operator is prefix when it is first in its row or follows another
 * operator or an opening fence; postfix when it is last and follows an operand, or is a closing
 * fence; infix otherwise. An operator whose source fixes its form (mo form=) keeps it. A closing fence counts as an
 * operand for what comes after it, so ") - b" is infix.
 *
 * Like a TeX Close atom, a postfix operator that follows an operand also counts as an operand: "n! + b" and "n!!"
 * resolve as ") + b" does. That holds for an operator the dictionary has only as postfix (`!`, `′`, `°`) and for one
 * that also has an infix entry (`%`) when what follows is an operator other than an opening fence, or nothing:
 * "50% + 3" and "5%)" but "a % b" and "a % (b + c)".
 * A relation (thick space on both sides, from lspace and rspace when set, else the dictionary) is never prefix unless
 * it is the first item of its row, so after another operator it is infix, or postfix when last: "x ∼ ∼ b". So is a
 * punctuation mark (the dictionary's 0/3 infix entries, such as `,` and `;`) right after a relation: "a =, b".
 *
 * Where TeX's spacing table gives no room, the layout drops the dictionary space; see drops(): between two relations
 * (Rel-Rel), after an opening fence (Open-Rel), before a closing one (Rel-Close) and between a relation and a
 * punctuation mark (Rel-Punct). Those are decided by atom class (see isRelation()), not by the resolved form, so a
 * relation at the start or end of a row drops them too, and an explicit form does not stop it. Space-like children
 * between the two atoms are skipped. A side the source gives explicitly is kept, except the thick space of a relation
 * class (`\mathrel`), which is only the class.
 * A relation first or last in a fence's content, `\left( = b \right)`, drops against the delimiter as well.
 *
 * The bars `|` and `‖` are the same character on both sides, so within a row they pair up: the 1st, 3rd, ...
 * of a kind opens (prefix) and the 2nd, 4th, ... closes (postfix, an operand for what follows). A last bar
 * with no partner ("a | b") is a relation: infix.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final class Forms
{
    /** The thick space of a relation, in mu.
     *
     * @internal Engine detail, not public API.
     */
    public const float THICK = 5.0;

    private const float TOLERANCE = 0.01;

    /**
     * @param list<Node> $children
     * @return list<?string> per child: prefix, infix or postfix for operators, null for anything else
     */
    public static function of(array $children): array
    {
        $forms = [];
        $count = count($children);
        $prevIsOperand = false;
        $prevRelation = false;
        $barTotals = [];
        foreach ($children as $child) {
            $child = Fallback::unwrap($child);
            if ($child instanceof Operator && $child->form === null && self::isBar($child->char)) {
                $barTotals[$child->char] = ($barTotals[$child->char] ?? 0) + 1;
            }
        }
        $barSeen = [];
        $first = true;
        // Space-like children (mspace) take no part in inferring forms: they are skipped, as in MathML Core.
        $last = $count - 1;
        while ($last > 0 && self::spaceLike($children[$last])) {
            $last--;
        }
        foreach ($children as $i => $child) {
            $child = Fallback::unwrap($child);
            $wasFirst = $first;
            $first = $first && self::spaceLike($child);
            if (! ($child instanceof Operator)) {
                $forms[] = null;
                $prevIsOperand = $prevIsOperand || ! self::spaceLike($child);
                $prevRelation = $prevRelation && self::spaceLike($child);
                continue;
            }
            $afterRelation = $prevRelation;
            $prevRelation = $isRel = self::isRelation($child);
            if ($child->form !== null) {
                $forms[] = $child->form;
                $prevIsOperand = $child->form === OperatorDictionary::FORM_POSTFIX;
                continue;
            }
            if (self::isBar($child->char)) {
                $k = $barSeen[$child->char] = ($barSeen[$child->char] ?? 0) + 1;
                if ($k === $barTotals[$child->char] && $k % 2 === 1) {
                    $forms[] = $prevIsOperand ? OperatorDictionary::FORM_INFIX : OperatorDictionary::FORM_PREFIX;
                    $prevRelation = $prevIsOperand && $isRel; // an infix bar is a relation, an opening one is not
                    $prevIsOperand = false;
                } elseif ($k % 2 === 1) {
                    $prevRelation = false;
                    $forms[] = OperatorDictionary::FORM_PREFIX;
                    $prevIsOperand = false;
                } else {
                    $prevRelation = false;
                    $forms[] = OperatorDictionary::FORM_POSTFIX;
                    $prevIsOperand = true;
                }
                continue;
            }
            $closing = OperatorDictionary::isClosing($child->char)
                || ($prevIsOperand && self::postfixOnly($child->char))
                || ($prevIsOperand && OperatorDictionary::hasForm($child->char, OperatorDictionary::FORM_POSTFIX) && self::operatorFollows($children, $i));
            $forms[] = match (true) {
                $closing => OperatorDictionary::FORM_POSTFIX,
                ! $prevIsOperand && ($wasFirst || ! ($isRel || ($afterRelation && self::isPunctuation($child)))) => OperatorDictionary::FORM_PREFIX,
                $i >= $last => OperatorDictionary::FORM_POSTFIX,
                default => OperatorDictionary::FORM_INFIX,
            };
            $prevIsOperand = $closing;
        }

        return $forms;
    }

    /**
     * Which sides of each operator the layout drops, for the spacing TeX gives no room: between two relations, between a
     * relation and a punctuation mark after it, after an opening fence and before a closing one. $open and $close say the
     * row sits inside a fence, so a relation first in it drops its left side and one last in it its right side.
     * Space-like children are skipped when looking for the neighbors, as TeX's spacing skips glue. A side is dropped when it
     * comes from the dictionary or is the thick space of a relation; any other explicit side stays. An explicit form does
     * not change the atom class, so it does not keep the drop.
     *
     * @param list<Node> $children
     * @param list<?string> $forms from of(); a bar is a relation only in its infix form
     * @return list<array{bool, bool}> per child: [drop lspace, drop rspace]
     */
    public static function drops(array $children, array $forms, bool $open = false, bool $close = false): array
    {
        $drops = array_fill(0, count($children), [false, false]);
        // The non-space-like children, in order: an operator is a neighbor only through its position here.
        $atoms = [];
        foreach ($children as $i => $child) {
            if (! self::spaceLike($child)) {
                $unwrapped = Fallback::unwrap($child);
                $atoms[] = [$i, $unwrapped instanceof Operator ? $unwrapped : null];
            }
        }
        $last = count($atoms) - 1;
        foreach ($atoms as $k => [$i, $op]) {
            if ($op === null || ! self::isRelation($op, $forms[$i])) {
                continue;
            }
            [$prevIndex, $prev] = $atoms[$k - 1] ?? [0, null];
            [$nextIndex, $next] = $atoms[$k + 1] ?? [0, null];
            $left = $k === 0 ? $open : ($prev !== null && (OperatorDictionary::isOpening($prev->char) || self::isRelation($prev, $forms[$prevIndex])));
            $right = $k === $last ? $close : ($next !== null && (OperatorDictionary::isClosing($next->char) || self::isPunctuation($next) || self::isRelation($next, $forms[$nextIndex])));
            $drops[$i] = [$left && self::dropsSide($op->lspace), $right && self::dropsSide($op->rspace)];
        }

        return $drops;
    }

    /** A side the dictionary supplies, or a relation's own thick space (TeX's \mathrel only sets the class), may be dropped. */
    private static function dropsSide(?float $side): bool
    {
        return $side === null || self::isThick($side);
    }

    /**
     * The thick space of a relation, 5mu: an explicit side this wide is only the class (`\mathrel`), so a drop may take it.
     * A MathML source writes it to four decimals of an em, hence the tolerance.
     *
     * @internal Engine detail, not public API.
     */
    public static function isThick(float $side): bool
    {
        return abs($side - self::THICK) < self::TOLERANCE;
    }

    /** A punctuation mark: 0 before and 3mu after, from the explicit sides or else the dictionary's infix entry (`,` `;` `:`). */
    private static function isPunctuation(Operator $op): bool
    {
        if (! OperatorDictionary::hasForm($op->char, OperatorDictionary::FORM_INFIX) && ($op->lspace === null || $op->rspace === null)) {
            return false;
        }
        $entry = OperatorDictionary::lookup($op->char, OperatorDictionary::FORM_INFIX);

        return abs(($op->lspace ?? $entry['lspace']) - 0.0) < self::TOLERANCE && abs(($op->rspace ?? $entry['rspace']) - 3.0) < self::TOLERANCE;
    }

    /**
     * The operator with the dropped sides set to zero; the operator itself when none is dropped.
     *
     * @internal Engine detail, not public API.
     */
    public static function withDrops(Operator $op, bool $dropL, bool $dropR): Operator
    {
        if (! $dropL && ! $dropR) {
            return $op;
        }

        return $op->withSpaces($dropL ? 0.0 : $op->lspace, $dropR ? 0.0 : $op->rspace);
    }

    /**
     * Whether the next item that is not space-like is an operator other than an opening fence: after a `%`, "(b + c)" is an
     * operand's start, as in "a % (b + c)".
     *
     * @param list<Node> $children
     */
    private static function operatorFollows(array $children, int $i): bool
    {
        for ($j = $i + 1, $n = count($children); $j < $n; $j++) {
            if (! self::spaceLike($children[$j])) {
                $next = Fallback::unwrap($children[$j]);

                return ($next instanceof Operator && ! OperatorDictionary::isOpening($next->char)) || $next instanceof Middle;
            }
        }

        return false;
    }

    /** A Space, or a Phantom of space-like content: occupies room but is no operand. */
    public static function spaceLike(Node $n): bool
    {
        $n = Fallback::unwrap($n);

        return $n instanceof Space || ($n instanceof Phantom && self::spaceLike($n->content));
    }

    /** An operator that is only ever postfix, like `!`: it ends an operand and leaves one behind. */
    private static function postfixOnly(string $char): bool
    {
        return OperatorDictionary::hasForm($char, OperatorDictionary::FORM_POSTFIX)
            && ! OperatorDictionary::hasForm($char, OperatorDictionary::FORM_INFIX);
    }

    /**
     * Whether the operator is a relation, TeX's Rel class: thick space on both sides, each the explicit side when set, else
     * the dictionary's infix entry. The class is the infix entry's whatever the position or form (`∼` is a relation at the
     * start of a row, where its own entry is 0/0), except for a bar, which is a relation only in its infix form ($form, when
     * given), so a pair of bars is not.
     *
     * @internal Engine detail, not public API.
     */
    public static function isRelation(Operator $op, ?string $form = null): bool
    {
        if ($form !== null && $form !== OperatorDictionary::FORM_INFIX && self::isBar($op->char)) {
            return false;
        }
        $entry = OperatorDictionary::hasForm($op->char, OperatorDictionary::FORM_INFIX) ? OperatorDictionary::lookup($op->char, OperatorDictionary::FORM_INFIX) : null;
        $l = $op->lspace ?? $entry['lspace'] ?? null;
        $r = $op->rspace ?? $entry['rspace'] ?? null;

        return $l !== null && $r !== null && $l >= self::THICK - self::TOLERANCE && $r >= self::THICK - self::TOLERANCE;
    }

    /**
     * The vertical bars `|` and `‖`, which pair up within a row.
     *
     * @internal Engine detail, not public API.
     */
    public static function isBar(string $char): bool
    {
        return $char === '|' || $char === '‖';
    }
}
