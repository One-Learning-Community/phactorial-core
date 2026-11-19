<?php

declare(strict_types=1);

namespace Phactorial\Render;

use Phactorial\Node\Color;
use Phactorial\Node\DisplayStyle;
use Phactorial\Node\Fallback;
use Phactorial\Node\Fenced;
use Phactorial\Node\Forms;
use Phactorial\Node\Fraction;
use Phactorial\Node\Grid;
use Phactorial\Node\Identifier;
use Phactorial\Node\Node;
use Phactorial\Node\Number;
use Phactorial\Node\Middle;
use Phactorial\Node\Operator;
use Phactorial\Node\Phantom;
use Phactorial\Node\Root;
use Phactorial\Node\Row;
use Phactorial\Node\Scripts;
use Phactorial\Node\Space;
use Phactorial\Node\TexSource;
use Phactorial\Node\Text;
use Phactorial\Node\UnderOver;
use Phactorial\OperatorDictionary;
use Phactorial\ScriptChars;
use Phactorial\Styles;

/**
 * A formula as linear Unicode text with plain letters, for outputs that have no math: table-of-contents entries,
 * outline titles, slugs, a text fallback. `x = (−b ± √(b² − 4ac))/(2a)`. Infix operators are set off by spaces and
 * prefix and postfix ones are not. Scripts become Unicode superscript and subscript characters when they are
 * digits, signs, parentheses or a single letter, and `^(…)` and `_(…)` otherwise.
 */
final class TextWriter
{
    private function __construct()
    {
    }

    public static function write(Node $root): string
    {
        return trim(self::node($root));
    }

    private static function node(Node $n): string
    {
        return match (true) {
            $n instanceof Identifier => self::plain($n->name),
            $n instanceof Number => $n->text,
            $n instanceof Operator => $n->char,
            $n instanceof Middle => ' ' . $n->char . ' ',
            $n instanceof Text => $n->text,
            $n instanceof Fraction => self::fraction($n),
            $n instanceof Root => self::root($n),
            $n instanceof Scripts => self::scripts($n),
            $n instanceof UnderOver => self::underOver($n),
            $n instanceof Fenced && $n->open === '{' && $n->close === '' && Fallback::unwrap($n->content) instanceof Grid => '{' . self::grid(Fallback::unwrap($n->content), ', '),
            $n instanceof Fenced => $n->open . ($n->content instanceof Fraction && ! $n->content->bar ? self::stacked($n->content) : self::node($n->content)) . $n->close,
            $n instanceof Grid => self::grid($n, ' '),
            $n instanceof Space => $n->em >= 0.15 ? ' ' : '',
            $n instanceof Phantom => '',
            $n instanceof Row => self::row($n),
            $n instanceof DisplayStyle, $n instanceof TexSource, $n instanceof Color => self::node($n->content),
            $n instanceof Fallback => $n->content === null ? '' : self::node($n->content),
            default => throw new \InvalidArgumentException('Unknown node ' . $n::class),
        };
    }

    /** Rows joined by "; " and cells by $sep: one line, whatever the shape. */
    private static function grid(Grid $g, string $sep): string
    {
        $rows = array_map(static function (array $row) use ($sep): string {
            $out = '';
            foreach ($row as $cell) {
                $text = trim(self::node($cell));
                if ($text === '') {
                    continue;
                }
                // A cell that already ends in the separator's comma (Wiris writes `x,`) is not given a second one.
                $out .= $out === '' ? $text : (str_ends_with($out, rtrim($sep)) ? ' ' : $sep) . $text;
            }

            return $out;
        }, $g->rows);

        return implode('; ', array_filter($rows, static fn (string $row): bool => $row !== ''));
    }

    private static function plain(string $name): string
    {
        $out = '';
        foreach (mb_str_split($name) as $ch) {
            $cp = mb_ord($ch);
            $out .= $cp === 0x210E ? 'h' : mb_chr(Styles::plain($cp));
        }

        return $out;
    }

    private static function row(Row $r): string
    {
        $forms = $r->forms();
        $out = '';
        $prev = null;
        // Whether the last thing written was an operator's trailing space, or an opening fence.
        $afterPad = false;
        $afterOpen = false;
        foreach ($r->nodes() as $i => $child) {
            $op = Fallback::unwrap($child);
            if ($prev !== null && self::isArrowStack(Fallback::unwrap($prev)) && $op instanceof Space && $op->em >= 0.15) {
                // The arrow has set off its right side already.
                $prev = $child;

                continue;
            }
            if ($op instanceof Space && $op->em >= 0.15 && str_ends_with($out, ' ')) {
                // The space is already written, by an operator or an earlier space.
                $prev = $child;

                continue;
            }
            $padded = false;
            $opens = false;
            if ($op instanceof Operator) {
                $bar = Forms::isBar($op->char);
                $closes = OperatorDictionary::isClosing($op->char) || ($bar && $forms[$i] === OperatorDictionary::FORM_POSTFIX);
                if ($closes && $afterPad) {
                    // The relation before a closing fence has written its trailing space: the fence takes none.
                    $out = substr($out, 0, -1);
                }
                $out .= match (true) {
                    $forms[$i] === OperatorDictionary::FORM_INFIX && ($op->char === ',' || $op->char === ';') => $op->char . ' ',
                    // A fence takes no padding, wherever its form fell.
                    OperatorDictionary::isOpening($op->char) || $closes => $op->char,
                    // An operator right after another one finds the space already written; after an opening fence there is none to write.
                    $forms[$i] === OperatorDictionary::FORM_INFIX => ($afterOpen || str_ends_with($out, ' ') ? '' : ' ') . $op->char . ' ',
                    default => $op->char,
                };
                $padded = $forms[$i] === OperatorDictionary::FORM_INFIX && ! $closes && ! OperatorDictionary::isOpening($op->char) && $op->char !== ',' && $op->char !== ';';
                $opens = OperatorDictionary::isOpening($op->char) || ($bar && $forms[$i] === OperatorDictionary::FORM_PREFIX);
            } elseif (self::isArrowStack($op)) {
                // A reaction arrow with its labels is a relation: set off by spaces, as the operator form is.
                $out .= (str_ends_with($out, ' ') ? '' : ' ') . self::node($child) . ' ';
            } else {
                // A function name set apart from its operand: "sin x", not "sinx"; so is a body after limits.
                if (($prev instanceof Identifier && mb_strlen($prev->name) > 1) || ($prev !== null && ! self::isArrowStack(Fallback::unwrap($prev)) && self::endsInLimits($prev))) {
                    $out .= ' ';
                }
                $piece = self::node($child);
                // A Middle's own spaces fold into the one an operator, a comma or an opening fence before it has written.
                if ($op instanceof Middle && ($afterOpen || str_ends_with($out, ' '))) {
                    $piece = ltrim($piece);
                }
                // Prescripts after a script would read as more scripts of it, so a space sets them off.
                if ($op instanceof Scripts && ($op->preSub !== null || $op->preSup !== null) && $prev !== null && Fallback::unwrap($prev) instanceof Scripts && $out !== '' && ! str_ends_with($out, ' ') && ! str_starts_with($piece, ' ')) {
                    $out .= ' ';
                }
                $out .= $piece;
            }
            $afterPad = $padded;
            $afterOpen = $opens;
            $prev = $child;
        }

        return $out;
    }

    /** True for an arrow stretched over or under a label: a relation between its neighbors. */
    private static function isArrowStack(Node $n): bool
    {
        return $n instanceof UnderOver && $n->base instanceof Operator && UnderOver::isArrow($n->base->char);
    }

    /** True for a node that reads as one token: no operator or space of its own to bind it into the text around it. */
    private static function atom(Node $n): bool
    {
        return match (true) {
            $n instanceof Identifier, $n instanceof Number, $n instanceof Fenced, $n instanceof Root, $n instanceof Scripts, $n instanceof UnderOver => true,
            $n instanceof Grid => count($n->rows) === 1 && $n->columns === 1,
            $n instanceof Fraction => ! $n->bar,
            $n instanceof Text => preg_match('/\s/u', $n->text) !== 1,
            $n instanceof Fallback => $n->content !== null && self::atom($n->content),
            $n instanceof TexSource, $n instanceof Color => self::atom($n->content),
            $n instanceof Row => count($n->children) === 1 && self::atom($n->children[0]),
            default => false,
        };
    }

    private static function wrap(Node $n): string
    {
        $s = self::node($n);

        return self::atom($n) ? $s : '(' . $s . ')';
    }

    private static function stacked(Fraction $f): string
    {
        return trim(self::node($f->numerator)) . '; ' . trim(self::node($f->denominator));
    }

    private static function fraction(Fraction $f): string
    {
        if (! $f->bar) {
            return '(' . self::stacked($f) . ')';
        }

        return self::wrap($f->numerator) . '/' . self::wrap($f->denominator);
    }

    private static function root(Root $r): string
    {
        $inner = self::wrap($r->radicand);
        if ($r->index === null) {
            return '√' . $inner;
        }
        $index = trim(self::node($r->index));

        return match ($index) {
            '3' => '∛' . $inner,
            '4' => '∜' . $inner,
            default => '(' . $index . ')√(' . trim(self::node($r->radicand)) . ')',
        };
    }

    private static function scripts(Scripts $s): string
    {
        $base = self::node($s->base);
        $pre = ($s->preSup !== null ? self::script($s->preSup, ScriptChars::SUP, '^') : '') . ($s->preSub !== null ? self::script($s->preSub, ScriptChars::SUB, '_') : '');
        // The marked form would read as a script of the atom before, so a space sets it off.
        $base = ($pre !== '' && ($pre[0] === '^' || $pre[0] === '_') ? ' ' : '') . $pre . $base;

        return $base . ($s->sub !== null ? self::script($s->sub, ScriptChars::SUB, '_') : '') . ($s->sup !== null ? self::script($s->sup, ScriptChars::SUP, '^') : '');
    }

    /**
     * An accent or bar follows its base as a combining mark, a brace is dropped (its label reads as a limit), and other
     * parts read as limits: `∑_(i=1)^n`.
     */
    private static function underOver(UnderOver $u): string
    {
        $overPart = UnderOver::blank($u->over) ? null : $u->over;
        $underPart = UnderOver::blank($u->under) ? null : $u->under;
        $over = UnderOver::operatorOf($overPart);
        $under = UnderOver::operatorOf($underPart);
        $marks = '';
        $braced = false;
        $overPlain = $overPart;
        $underPlain = $underPart;
        if ($over !== null) {
            $isBar = in_array($over->char, UnderOver::BARS, true);
            if ($isBar || ($u->accent && ! UnderOver::isBrace($over, true))) {
                $marks .= $isBar ? "\u{0304}" : (Styles::accentMark($over->char) ?? $over->char);
                $overPlain = null;
            } elseif (UnderOver::isBrace($over, true)) {
                $braced = true;
                $overPlain = null;
            }
        }
        if ($under !== null) {
            $isBar = in_array($under->char, UnderOver::BARS, true);
            if ($isBar || ($u->accentUnder && ! UnderOver::isBrace($under, false))) {
                $marks .= $isBar ? "\u{0332}" : (Styles::accentMark($under->char) ?? $under->char);
                $underPlain = null;
            } elseif (UnderOver::isBrace($under, false)) {
                $braced = true;
                $underPlain = null;
            }
        }

        $base = $braced ? self::wrap($u->base) : trim(self::node($u->base));
        if ($marks !== '') {
            $base = (mb_strlen($base) === 1 ? $base : '(' . $base . ')') . $marks;
        }
        if ($underPlain === null && $overPlain === null) {
            return $base;
        }
        if ($marks === '' && ! $braced && ! self::atom($u->base) && UnderOver::operatorOf($u->base) === null) {
            $base = '(' . $base . ')';
        }

        return $base . ($underPlain !== null ? self::limit($underPlain, '_') : '') . ($overPlain !== null ? self::limit($overPlain, '^') : '');
    }

    /** A limit as `_(i=1)` or `^n`: always the marked form, with the spaces of math dropped. */
    private static function limit(Node $n, string $mark): string
    {
        $s = trim(self::node($n));
        if (! self::hasText($n)) {
            $s = str_replace(' ', '', $s);
        }

        return $mark . (self::atom($n) ? $s : '(' . $s . ')');
    }

    private static function hasText(Node $n): bool
    {
        return $n instanceof Text || array_any($n->children(), self::hasText(...));
    }

    /** True when the node ends in limits that read as text: a body after it needs a space. */
    private static function endsInLimits(Node $n): bool
    {
        if ($n instanceof Scripts) {
            $op = UnderOver::operatorOf($n->base);

            return $op !== null && $op->isLargeop(OperatorDictionary::FORM_PREFIX);
        }

        if (! ($n instanceof UnderOver)) {
            return false;
        }
        $limit = static function (?Node $part, bool $accent): bool {
            $op = UnderOver::operatorOf($part);

            return $part !== null && ! ($op !== null && ($accent || in_array($op->char, UnderOver::BARS, true) || UnderOver::isBrace($op, true) || UnderOver::isBrace($op, false)));
        };

        return $limit(UnderOver::blank($n->under) ? null : $n->under, $n->accentUnder) || $limit(UnderOver::blank($n->over) ? null : $n->over, $n->accent);
    }

    /** @param array<int|string, string> $map */
    private static function script(Node $n, array $map, string $mark): string
    {
        $s = str_replace(' ', '', self::node($n));
        $chars = mb_str_split($s);
        $letters = count(array_filter($chars, static fn (string $c): bool => preg_match('/\p{L}/u', $c) === 1));
        // More than one letter would read as a word in tiny letters, so that stays ^(kt).
        if ($s !== '' && $letters <= 1 && array_all($chars, static fn (string $c): bool => isset($map[$c]))) {
            return implode('', array_map(static fn (string $c): string => $map[$c], $chars));
        }

        return $mark . (self::atom($n) ? $s : '(' . trim(self::node($n)) . ')');
    }
}
