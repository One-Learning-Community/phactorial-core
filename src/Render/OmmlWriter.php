<?php

declare(strict_types=1);

namespace Phactorial\Render;

use Phactorial\Colors;
use Phactorial\Node\Color;
use Phactorial\Node\DisplayStyle;
use Phactorial\Node\Fallback;
use Phactorial\Node\Fenced;
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
use Phactorial\Styles;

/**
 * Serializes a formula tree as Word OMML. The output declares no namespaces: the caller
 * declares xmlns:m and xmlns:w. Word always uses Cambria Math for equations.
 */
final class OmmlWriter
{
    private function __construct()
    {
    }

    public static function oMath(Node $root, ?RunFormat $format = null): string
    {
        return '<m:oMath>' . self::node($root, $format) . '</m:oMath>';
    }

    /**
     * @internal Engine detail, not public API.
     *
     * @throws \InvalidArgumentException for an equation alignment other than left, center or right
     */
    public static function checkAlignment(string $jc): void
    {
        if (! in_array($jc, ['left', 'center', 'right'], true)) {
            throw new \InvalidArgumentException('Unsupported equation alignment "' . $jc . '"');
        }
    }

    public static function oMathPara(Node $root, string $align = 'center', ?RunFormat $format = null): string
    {
        self::checkAlignment($align);

        return '<m:oMathPara><m:oMathParaPr><m:jc m:val="' . Xml::text($align) . '"/></m:oMathParaPr>'
            . self::oMath($root, $format) . '</m:oMathPara>';
    }

    private static function rPr(?RunFormat $f): string
    {
        $out = '<w:rPr><w:rFonts w:ascii="Cambria Math" w:hAnsi="Cambria Math" w:cs="Cambria Math"/>';
        if ($f?->colorHex !== null) {
            $out .= '<w:color w:val="' . Xml::text($f->colorHex) . '"/>';
        }
        if ($f?->sizeHalfPoints !== null) {
            $out .= '<w:sz w:val="' . $f->sizeHalfPoints . '"/><w:szCs w:val="' . $f->sizeHalfPoints . '"/>';
        }

        if ($f?->shadingHex !== null) {
            $out .= '<w:shd w:val="clear" w:color="auto" w:fill="' . Xml::text($f->shadingHex) . '"/>';
        }

        return $out . '</w:rPr>';
    }

    /** $f with the color of a Color node ($color is lowercase #rrggbb); $f itself when there is none or it is not valid. */
    private static function colored(?RunFormat $f, ?string $color): ?RunFormat
    {
        if ($color === null || ! Colors::isHex($color)) {
            return $f;
        }

        return new RunFormat(strtoupper(substr($color, 1)), $f?->sizeHalfPoints, $f?->shadingHex);
    }

    /** The paragraph-mark properties that make Word draw an object's own parts (bars, brackets, operators) in the run color. */
    private static function ctrlPr(?RunFormat $f): string
    {
        return $f?->colorHex === null ? '' : '<m:ctrlPr>' . self::rPr($f) . '</m:ctrlPr>';
    }

    /** A property element holding $inner and then ctrlPr (the schema's last child); left out when both are empty unless $always. */
    private static function pr(string $tag, string $inner, ?RunFormat $f, bool $always = false): string
    {
        $inner .= self::ctrlPr($f);

        return $inner === '' && ! $always ? '' : '<m:' . $tag . '>' . $inner . '</m:' . $tag . '>';
    }

    /** @param ''|'p'|'nor' $mode 'p' for upright math, 'nor' for normal text; the two are alternatives in m:rPr */
    private static function run(string $text, ?RunFormat $f, string $mode = ''): string
    {
        $mPr = match ($mode) {
            'p' => '<m:rPr><m:sty m:val="p"/></m:rPr>',
            'nor' => '<m:rPr><m:nor/></m:rPr>',
            default => '',
        };

        return '<m:r>' . $mPr . self::rPr($f)
            . '<m:t xml:space="preserve">' . Xml::text($text) . '</m:t></m:r>';
    }

    private static function node(Node $n, ?RunFormat $f): string
    {
        return match (true) {
            $n instanceof Identifier => self::run($n->upright ? $n->name : self::plain($n->name), $f, $n->upright ? 'p' : ''),
            $n instanceof Number => self::run($n->text, $f),
            $n instanceof Operator => self::run($n->char, $f, preg_match('/^\\p{L}+$/u', $n->char) === 1 ? 'p' : ''),
            $n instanceof Middle => self::run($n->char, $f),
            $n instanceof Text => self::run($n->text, $f, 'nor'),
            $n instanceof Space => self::space($n->em, $f),
            $n instanceof Phantom => '<m:phant>' . self::pr('phantPr', '<m:show m:val="0"/>', $f, true) . '<m:e>' . self::node($n->content, $f) . '</m:e></m:phant>',
            $n instanceof Color => self::node($n->content, self::colored($f, $n->color)),
            $n instanceof Fraction => '<m:f>' . self::pr('fPr', $n->bar ? '' : '<m:type m:val="noBar"/>', $f) . '<m:num>' . self::node($n->numerator, $f) . '</m:num><m:den>' . self::node($n->denominator, $f) . '</m:den></m:f>',
            $n instanceof Root => self::root($n, $f),
            $n instanceof Scripts => self::scripts($n, $f),
            $n instanceof UnderOver => self::underOver($n, $f),
            $n instanceof Fenced => self::fenced($n, $f),
            $n instanceof Grid => self::grid($n, $f),
            $n instanceof Row => self::sequence($n->items(), $f),
            $n instanceof DisplayStyle, $n instanceof TexSource => self::node($n->content, $f),
            $n instanceof Fallback => $n->content === null ? '' : self::node($n->content, $f),
            default => throw new \InvalidArgumentException('Unknown node ' . $n::class),
        };
    }

    /** Whether $char fits an attribute that holds one character (one UTF-16 unit, as the schema counts). */
    private static function single(string $char): bool
    {
        return mb_strlen($char) === 1 && mb_ord($char) <= 0xFFFF;
    }

    /** A delimiter longer than one character is written as text inside the group, with an empty delimiter attribute. */
    private static function fenced(Fenced $fence, ?RunFormat $f): string
    {
        $open = self::single($fence->open);
        $close = self::single($fence->close);
        $parts = [];
        $sep = self::separator($fence->content, $f, $parts);
        $first = $open || $fence->open === '' ? '' : self::run($fence->open, $f);
        $last = $close || $fence->close === '' ? '' : self::run($fence->close, $f);
        $body = $sep === null
            ? '<m:e>' . $first . self::node($fence->content, $f) . $last . '</m:e>'
            : implode('', array_map(
                static fn (array $part, int $i): string => '<m:e>' . ($i === 0 ? $first : '') . self::sequence($part, $f) . ($i === count($parts) - 1 ? $last : '') . '</m:e>',
                $parts,
                array_keys($parts),
            ));

        return '<m:d>' . self::pr('dPr', '<m:begChr m:val="' . ($open ? Xml::text($fence->open) : '') . '"/>'
            . ($sep === null ? '' : '<m:sepChr m:val="' . Xml::text($sep) . '"/>')
            . '<m:endChr m:val="' . ($close ? Xml::text($fence->close) : '') . '"/>', $f, true) . $body . '</m:d>';
    }

    /**
     * The one character that separates a fence's parts when its content is a Row with top-level Middles that all
     * share a single-unit character (Word's m:sepChr holds one for all separators). Null leaves the Middles as runs.
     * m:dPr has one ctrlPr for the whole group, so a Middle drawn in a color other than the fence's stays a run.
     *
     * @param list<list<array{Node, ?string}>> $parts the items between the Middles, each with its inline color
     */
    private static function separator(Node $content, ?RunFormat $f, array &$parts): ?string
    {
        $row = Fallback::unwrap($content);
        if (! $row instanceof Row) {
            return null;
        }
        $base = Fallback::color($content);
        $sep = null;
        $current = [];
        $found = [];
        foreach ($row->items() as [$child, $own]) {
            $m = Fallback::unwrap($child);
            if (! $m instanceof Middle) {
                $current[] = [$child, $own ?? $base];
                continue;
            }
            if (! self::single($m->char) || ($sep !== null && $sep !== $m->char)) {
                return null;
            }
            $color = self::colored($f, Fallback::color($child) ?? $own ?? $base);
            if ($color?->colorHex !== $f?->colorHex) {
                return null;
            }
            $sep = $m->char;
            $found[] = $current;
            $current = [];
        }
        if ($sep === null) {
            return null;
        }
        $found[] = $current;
        $parts = $found;

        return $sep;
    }

    /** Word's matrix. Row alignment, display style and column spacing have no counterpart in OMML and are not written. */
    private static function grid(Grid $g, ?RunFormat $f): string
    {
        if ($g->columns === 0) {
            return '';
        }
        if ($g->alignment) {
            // Word's equation array: one line per row, the alignment points marked by `&` runs. Between two
            // column pairs (the odd gaps) Word has no spacing of its own, so em-space runs carry the gap.
            $out = '<m:eqArr>' . self::pr('eqArrPr', '', $f);
            $seps = [];
            for ($j = 0; $j < $g->columns - 1; $j++) {
                $gap = $j % 2 === 1 ? $g->columnSpacing[$j] ?? 0.0 : 0.0;
                $seps[$j] = self::run('&', $f) . self::space($gap, $f);
            }
            foreach ($g->rows as $row) {
                $content = '';
                foreach ($row as $j => $cell) {
                    $content .= ($j > 0 ? $seps[$j - 1] ?? self::run('&', $f) : '') . self::node($cell, $f);
                }
                // As in the matrix: LibreOffice shows a red "¿" for an empty m:e.
                $out .= '<m:e>' . ($content === '' ? self::run(' ', $f) : $content) . '</m:e>';
            }

            return $out . '</m:eqArr>';
        }
        $out = '<m:m><m:mPr><m:plcHide m:val="1"/><m:mcs>';
        foreach ($g->columnAlign as $align) {
            $out .= '<m:mc><m:mcPr><m:count m:val="1"/><m:mcJc m:val="' . $align . '"/></m:mcPr></m:mc>';
        }
        $out .= '</m:mcs>' . self::ctrlPr($f) . '</m:mPr>';
        foreach ($g->rows as $row) {
            $out .= '<m:mr>';
            foreach ($row as $cell) {
                $content = self::node($cell, $f);
                // LibreOffice shows a red "¿" and collapses the rows for an empty m:e; a run holding one space is fine.
                $out .= '<m:e>' . ($content === '' ? self::run(' ', $f) : $content) . '</m:e>';
            }
            $out .= '</m:mr>';
        }

        return $out . '</m:m>';
    }

    /** Unicode spaces approximating the width; a negative space cannot be written and gives nothing. */
    private static function space(float $em, ?RunFormat $f): string
    {
        $text = match (true) {
            $em <= 0 => '',
            $em <= 0.2 => "\u{2009}",
            $em <= 0.3 => "\u{2005}",
            $em <= 0.4 => "\u{2004}",
            $em <= 0.6 => "\u{2002}",
            $em < 1 => "\u{2003}",
            default => str_repeat("\u{2003}", (int) floor($em)) . self::fraction($em - floor($em)),
        };

        return $text === '' ? '' : self::run($text, $f);
    }

    /** The Unicode space nearest a fraction of an em; nothing below a tenth. */
    private static function fraction(float $f): string
    {
        return match (true) {
            $f < 0.1 => '',
            $f <= 0.2 => "\u{2009}",
            $f <= 0.3 => "\u{2005}",
            $f <= 0.4 => "\u{2004}",
            $f <= 0.6 => "\u{2002}",
            default => "\u{2003}",
        };
    }

    private static function root(Root $r, ?RunFormat $f): string
    {
        $e = '<m:e>' . self::node($r->radicand, $f) . '</m:e>';
        if ($r->index === null) {
            return '<m:rad>' . self::pr('radPr', '<m:degHide m:val="1"/>', $f) . '<m:deg/>' . $e . '</m:rad>';
        }

        return '<m:rad>' . self::pr('radPr', '<m:degHide m:val="0"/>', $f) . '<m:deg>' . self::node($r->index, $f) . '</m:deg>' . $e . '</m:rad>';
    }

    private static function scripts(Scripts $s, ?RunFormat $f): string
    {
        $nary = self::nary($s);
        if ($nary !== null) {
            return self::naryXml($nary, null, $f);
        }
        $e = self::node($s->base, $f);
        if ($s->sub !== null || $s->sup !== null) {
            $tag = match (true) {
                $s->sub !== null && $s->sup !== null => 'm:sSubSup',
                $s->sup !== null => 'm:sSup',
                default => 'm:sSub',
            };
            $e = '<' . $tag . '>' . self::pr(substr($tag, 2) . 'Pr', '', $f) . '<m:e>' . $e . '</m:e>'
                . ($s->sub === null ? '' : '<m:sub>' . self::node($s->sub, $f) . '</m:sub>')
                . ($s->sup === null ? '' : '<m:sup>' . self::node($s->sup, $f) . '</m:sup>')
                . '</' . $tag . '>';
        }
        if ($s->preSub === null && $s->preSup === null) {
            return $e;
        }

        // Word's pre-script element needs both slots; a missing one is empty.
        return '<m:sPre>' . self::pr('sPrePr', '', $f) . '<m:sub>' . ($s->preSub === null ? '' : self::node($s->preSub, $f)) . '</m:sub><m:sup>'
            . ($s->preSup === null ? '' : self::node($s->preSup, $f)) . '</m:sup><m:e>' . $e . '</m:e></m:sPre>';
    }

    /**
     * Children side by side. A large operator with limits takes the next child as its body, as Word's n-ary does
     * (skipping spaces, which stay in the body ahead of it), unless that child is an operator (then the body is empty).
     * A body that is itself an n-ary takes its own body in turn.
     *
     * @param list<array{Node, ?string}> $items each child with the inline color around it (see Row::items())
     */
    private static function sequence(array $items, ?RunFormat $f): string
    {
        $out = '';
        $i = 0;
        $count = count($items);
        while ($i < $count) {
            $out .= self::item($items, $i, $f);
        }

        return $out;
    }

    /** @param list<array{Node, ?string}> $items */
    private static function item(array $items, int &$i, ?RunFormat $f): string
    {
        [$child, $color] = $items[$i++];
        $cf = self::colored($f, $color);
        $nary = self::nary($child);
        if ($nary === null) {
            return self::node($child, $cf);
        }
        $spaces = '';
        $j = $i;
        while (isset($items[$j]) && Fallback::unwrap($items[$j][0]) instanceof Space) {
            $spaces .= self::node($items[$j][0], self::colored($f, $items[$j][1]));
            $j++;
        }
        $next = $items[$j][0] ?? null;
        $nf = self::colored($cf, Fallback::color($child));
        if ($next === null || Fallback::unwrap($next) instanceof Operator) {
            return self::naryXml($nary, null, $nf);
        }
        $i = $j;

        return self::naryXml($nary, $spaces . self::item($items, $i, $f), $nf);
    }

    /**
     * The parts of a large operator with limits: an UnderOver (limits above and below) or Scripts (beside it).
     *
     * @return ?array{Operator, ?Node, ?Node, bool, ?string} operator, lower limit, upper limit, whether they sit above and below,
     *                                                       and the color of a Color wrapped around the operator alone
     */
    private static function nary(Node $n): ?array
    {
        $n = Fallback::unwrap($n);
        if (! ($n instanceof UnderOver || $n instanceof Scripts)) {
            return null;
        }
        if ($n instanceof Scripts && ($n->preSub !== null || $n->preSup !== null)) {
            return null;
        }
        $op = UnderOver::operatorOf($n->base);
        if ($op === null || ! self::single($op->char) || ! $op->isLargeop(OperatorDictionary::FORM_PREFIX)) {
            return null;
        }
        $opColor = UnderOver::colorOf($n->base);

        return $n instanceof UnderOver ? [$op, $n->under, $n->over, true, $opColor] : [$op, $n->sub, $n->sup, false, $opColor];
    }

    /** @param array{Operator, ?Node, ?Node, bool, ?string} $nary */
    private static function naryXml(array $nary, ?string $body, ?RunFormat $f): string
    {
        [$op, $sub, $sup, $stacked, $opColor] = $nary;

        return '<m:nary>' . self::pr('naryPr', '<m:chr m:val="' . Xml::text($op->char) . '"/><m:limLoc m:val="' . ($stacked ? 'undOvr' : 'subSup') . '"/>'
            . ($sub === null ? '<m:subHide m:val="1"/>' : '') . ($sup === null ? '<m:supHide m:val="1"/>' : ''), self::colored($f, $opColor), true)
            . ($sub === null ? '<m:sub/>' : '<m:sub>' . self::node($sub, $f) . '</m:sub>')
            . ($sup === null ? '<m:sup/>' : '<m:sup>' . self::node($sup, $f) . '</m:sup>')
            . ($body === null ? '<m:e/>' : '<m:e>' . $body . '</m:e>')
            . '</m:nary>';
    }

    /** Limits, accents, bars and braces: Word's own structures where it has one, a plain limit stack otherwise. */
    private static function underOver(UnderOver $u, ?RunFormat $f): string
    {
        $nary = self::nary($u);
        if ($nary !== null) {
            return self::naryXml($nary, null, $f);
        }
        $arrow = UnderOver::operatorOf($u->base);
        if ($arrow !== null && Fallback::unwrap($u->base) === $arrow && UnderOver::isStretchy($arrow) && UnderOver::isArrow($arrow->char) && ($u->over !== null || $u->under !== null) && self::single($arrow->char)) {
            // A label that draws nothing (the empty one a label-less chemistry arrow carries) is no label.
            $over = UnderOver::blank($u->over) ? null : $u->over;
            $under = UnderOver::blank($u->under) ? null : $u->under;
            if ($over === null && $under === null) {
                // KaTeX draws the long arrows for these.
                return self::node(new Operator(UnderOver::LONG_ARROWS[$arrow->char] ?? $arrow->char), self::colored($f, UnderOver::colorOf($u->base)));
            }

            return self::labeledArrow($over, $under, $arrow->char, $f, self::colored($f, UnderOver::colorOf($u->base)));
        }
        $out = self::node($u->base, $f);
        // A part that draws nothing is no limit: it would write an empty <m:lim>.
        $underPart = UnderOver::blank($u->under) ? null : $u->under;
        $overPart = UnderOver::blank($u->over) ? null : $u->over;
        if ($underPart !== null) {
            $op = UnderOver::operatorOf($underPart);
            $pf = self::colored($f, UnderOver::colorOf($underPart));
            $out = match (true) {
                $op !== null && in_array($op->char, UnderOver::BARS, true) => self::bar($out, 'bot', $pf),
                $op !== null && UnderOver::isBrace($op, false) => self::groupChr($out, $op->char, 'bot', 'top', $pf),
                $op !== null && $u->accentUnder => self::groupChr($out, Styles::accentMark($op->char) ?? $op->char, 'bot', 'top', $pf),
                default => '<m:limLow>' . self::pr('limLowPr', '', $f) . '<m:e>' . $out . '</m:e><m:lim>' . self::node($underPart, $f) . '</m:lim></m:limLow>',
            };
        }
        if ($overPart !== null) {
            $op = UnderOver::operatorOf($overPart);
            $pf = self::colored($f, UnderOver::colorOf($overPart));
            $out = match (true) {
                $op !== null && in_array($op->char, UnderOver::BARS, true) => self::bar($out, 'top', $pf),
                $op !== null && $u->accent => self::accent($out, Styles::accentMark($op->char) ?? $op->char, $pf, $overPart),
                $op !== null && UnderOver::isBrace($op, true) => self::groupChr($out, $op->char, 'top', 'bot', $pf),
                default => '<m:limUpp>' . self::pr('limUppPr', '', $f) . '<m:e>' . $out . '</m:e><m:lim>' . self::node($overPart, $f) . '</m:lim></m:limUpp>',
            };
        }

        return $out;
    }

    /**
     * A stretchy arrow with labels. Word stretches a group character to the width of its content, and puts the
     * content on the baseline at full size, so the arrow is grouped over an invisible copy of the wider label
     * (zero height, width kept) and the real labels, at script size, are limits around it.
     */
    private static function labeledArrow(?Node $overNode, ?Node $underNode, string $char, ?RunFormat $f, ?RunFormat $arrowF): string
    {
        $over = $overNode === null ? null : self::node($overNode, $f);
        $under = $underNode === null ? null : self::node($underNode, $f);
        $wide = $under === null || ($over !== null && self::textLength($over) >= self::textLength($under)) ? $over : $under;
        // An en space on each side of the hidden label: the arrow reaches past its label, and a short label still gets a long arrow.
        $pad = self::run("\u{2002}", $f);
        $out = self::groupChr('<m:phant>' . self::pr('phantPr', '<m:show m:val="0"/><m:zeroAsc m:val="1"/><m:zeroDesc m:val="1"/>', $f, true) . '<m:e>' . $pad . $wide . $pad . '</m:e></m:phant>', $char, 'top', 'bot', $arrowF);
        if ($over !== null) {
            $out = '<m:limUpp>' . self::pr('limUppPr', '', $f) . '<m:e>' . $out . '</m:e><m:lim>' . $over . '</m:lim></m:limUpp>';
        }

        return $under === null ? $out : '<m:limLow>' . self::pr('limLowPr', '', $f) . '<m:e>' . $out . '</m:e><m:lim>' . $under . '</m:lim></m:limLow>';
    }

    /** Characters of text in a piece of OMML: a rough width for choosing the longer label. */
    private static function textLength(string $xml): int
    {
        preg_match_all('#<m:t[^>]*>([^<]*)</m:t>#', $xml, $m);

        return mb_strlen(html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1));
    }

    private static function bar(string $e, string $pos, ?RunFormat $f): string
    {
        return '<m:bar>' . self::pr('barPr', '<m:pos m:val="' . $pos . '"/>', $f, true) . '<m:e>' . $e . '</m:e></m:bar>';
    }

    /** An accent over $e; a mark longer than one character is a limit above it instead. */
    private static function accent(string $e, string $mark, ?RunFormat $f, Node $over): string
    {
        if (! self::single($mark)) {
            return '<m:limUpp>' . self::pr('limUppPr', '', $f) . '<m:e>' . $e . '</m:e><m:lim>' . self::node($over, $f) . '</m:lim></m:limUpp>';
        }

        return '<m:acc>' . self::pr('accPr', '<m:chr m:val="' . Xml::text($mark) . '"/>', $f, true) . '<m:e>' . $e . '</m:e></m:acc>';
    }

    private static function groupChr(string $e, string $char, string $pos, string $vertJc, ?RunFormat $f): string
    {
        if (! self::single($char)) {
            $tag = $pos === 'bot' ? 'limLow' : 'limUpp';

            return '<m:' . $tag . '>' . self::pr($tag . 'Pr', '', $f) . '<m:e>' . $e . '</m:e><m:lim>' . self::run($char, $f) . '</m:lim></m:' . $tag . '>';
        }

        return '<m:groupChr>' . self::pr('groupChrPr', '<m:chr m:val="' . Xml::text($char) . '"/><m:pos m:val="' . $pos . '"/><m:vertJc m:val="' . $vertJc . '"/>', $f, true) . '<m:e>' . $e . '</m:e></m:groupChr>';
    }

    /** Math italic code points back to the plain letter: Word italicizes letters itself. */
    private static function plain(string $name): string
    {
        $out = '';
        foreach (mb_str_split($name) as $ch) {
            $cp = mb_ord($ch);
            $out .= $cp === 0x210E ? 'h' : mb_chr(Styles::plain($cp));
        }

        return $out;
    }
}
