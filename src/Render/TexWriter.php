<?php

declare(strict_types=1);

namespace Phactorial\Render;

use Phactorial\Math;
use Phactorial\Node\Color;
use Phactorial\Node\DisplayStyle;
use Phactorial\Node\Fallback;
use Phactorial\Node\Fenced;
use Phactorial\Node\Forms;
use Phactorial\Node\Fraction;
use Phactorial\Node\Grid;
use Phactorial\Node\Identifier;
use Phactorial\Node\Middle;
use Phactorial\Node\Node;
use Phactorial\Node\Number;
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
use Phactorial\Tex\TexClass;
use Phactorial\Tex\TexReader;
use Phactorial\Tex\TexSymbols;

/**
 * Writes a node tree as standard TeX, the kind KaTeX accepts: no package-specific macros, one spelling per construct.
 * A tree with no standard spelling is written as the nearest TeX and the difference is dropped.
 */
final class TexWriter
{
    /** Text-mode escapes. */
    private const array TEXT_ESCAPES = [
        '\\' => '\textbackslash{}', '{' => '\{', '}' => '\}', '$' => '\$', '&' => '\&', '#' => '\#', '^' => '\textasciicircum{}',
        '_' => '\_', '%' => '\%', '~' => '\textasciitilde{}',
    ];

    /** Characters TeX reads as something else in math, written as a command. */
    private const array MATH_ESCAPES = [
        '\\' => '\backslash', '{' => '\{', '}' => '\}', '$' => '\$', '&' => '\&', '#' => '\#', '^' => '\char"5E',
        '_' => '\_', '%' => '\%', '~' => '\char"7E',
    ];

    /** Ordinary symbols that are safe unbraced as a script argument. */
    private const array BARE_SCRIPT_SYMBOLS = ['infty', 'partial', 'nabla', 'hbar', 'ell', 'emptyset', 'prime', 'aleph'];

    /** @var array<int, true> ids of the TexSource nodes that may be written as an equation number: those at the top of the formula */
    private static array $topLevel = [];

    private function __construct()
    {
    }

    public static function write(Node $root): string
    {
        $saved = self::$topLevel;
        self::$topLevel = [];
        $tagged = false;
        foreach (self::topChildren($root) as $child) {
            if ($child instanceof TexSource && str_starts_with($child->tex, '\\tag')) {
                // KaTeX rejects a second equation number.
                if ($tagged) {
                    continue;
                }
                $tagged = true;
            }
            self::$topLevel[spl_object_id($child)] = true;
        }
        try {
            return self::trimTex(self::w($root, false));
        } finally {
            self::$topLevel = $saved;
        }
    }

    /**
     * The nodes at the top of the formula: through a display switch, and through an unbraced color switch, whose body runs to
     * the end of the formula (`\color{red} x \tag{1}` still has its tag at the top).
     *
     * @return list<Node>
     */
    private static function topChildren(Node $n): array
    {
        $n = self::strip($n);
        while ($n instanceof DisplayStyle) {
            // `\displaystyle x \tag{1}` reads as a switch around the whole formula: its tag is still at the top.
            $n = self::strip($n->content);
        }
        $out = [];
        foreach ($n instanceof Row ? $n->children : [$n] as $child) {
            $out[] = $child;
            if ($child instanceof TexSource && $child->isColorSwitch()) {
                $body = self::strip($child->content);
                array_push($out, ...self::topChildren($body instanceof Color ? $body->content : $body));
            }
        }

        return $out;
    }

    /** trim(), except that a control space `\ ` at the very end keeps its space. */
    private static function trimTex(string $tex): string
    {
        $tex = trim($tex);
        if (preg_match('/\\\\+$/', $tex, $m) === 1 && strlen($m[0]) % 2 === 1) {
            $tex .= ' ';
        }

        return $tex;
    }

    private static function w(Node $n, bool $inFence): string
    {
        return match (true) {
            $n instanceof Fallback => $n->content === null ? '' : self::w($n->content, $inFence),
            $n instanceof TexSource => self::source($n, $inFence),
            $n instanceof Color => $n->inline
                ? '{\color{' . $n->color . '} ' . self::w($n->content, false) . '}'
                : self::textcolor($n),
            $n instanceof Row => self::row($n, $inFence),
            $n instanceof Identifier => self::identifier($n),
            // A MathML-only number such as '1,000' is written raw: the nearest TeX.
            $n instanceof Number => self::escapeAll($n->text),
            $n instanceof Text => '\text{' . self::textEscape($n->text) . '}',
            $n instanceof Operator => self::operator($n),
            $n instanceof Middle => $inFence && TexSymbols::delimiter($n->char) !== null ? '\middle' . TexSymbols::delimiter($n->char) : self::symbol($n->char),
            $n instanceof Space => self::space($n->em),
            $n instanceof Phantom => '\phantom{' . self::w($n->content, false) . '}',
            $n instanceof Fraction => $n->bar
                ? '\frac{' . self::w($n->numerator, false) . '}{' . self::w($n->denominator, false) . '}'
                : '{' . self::w($n->numerator, false) . ' \atop ' . self::w($n->denominator, false) . '}',
            $n instanceof Root => self::root($n),
            $n instanceof Scripts => self::scripts($n),
            $n instanceof UnderOver => self::writeUnderOver($n),
            $n instanceof Fenced => self::fenced($n),
            $n instanceof Grid => self::gridBody($n, true),
            $n instanceof DisplayStyle => '{' . ($n->display ? '\displaystyle ' : '\textstyle ') . self::w($n->content, false) . '}',
            default => throw new \InvalidArgumentException('Unknown node ' . $n::class),
        };
    }

    /**
     * `\textcolor`, with the kerns of a scripted or stacked operator outside the group, as operatorPiece() does for a bare one:
     * the group would otherwise hold spacing that belongs to the operator's place in the row.
     */
    private static function textcolor(Color $c): string
    {
        $inner = self::w($c->content, false);
        $content = self::strip($c->content);
        $base = $content instanceof Scripts || $content instanceof UnderOver ? $content->base : null;
        if ($base !== null && UnderOver::nameNode($base) instanceof Operator && preg_match('/^((?:\\\\mkern-?[0-9.]+mu )?)(.*?)((?: \\\\mkern-?[0-9.]+mu)?)$/s', $inner, $m) === 1) {
            return $m[1] . '\textcolor{' . $c->color . '}{' . $m[2] . '}' . $m[3];
        }

        return '\textcolor{' . $c->color . '}{' . $inner . '}';
    }

    private static function row(Row $row, bool $inFence): string
    {
        $mod = self::modular($row);
        if ($mod !== null) {
            return $mod;
        }
        $pieces = [];
        $afterNumber = false;
        $glued = [];
        $previous = null;
        // The form the position gives each operator: an explicit form is not written, so TeX re-derives it from position.
        $forms = Forms::of(array_map(static function (Node $k): Node {
            $o = self::strip($k);

            return $o instanceof Operator && $o->form !== null ? $o->withForm(null) : $k;
        }, $row->children));
        $switchAt = $inFence ? self::colorSwitchAt($row->children) : null;
        $current = null;
        foreach ($row->children as $index => $kid) {
            $color = null;
            if ($switchAt !== null && $index >= $switchAt) {
                // From the switch on, each child's color is written as a switch, which TeX reads to the end of the fence.
                $outer = self::strip($kid);
                if ($outer instanceof Color) {
                    $color = $outer->color;
                    $kid = $outer->content;
                }
            }
            $bare = self::strip($kid);
            if ($bare instanceof Row) {
                $piece = self::modular($bare) ?? '{' . self::w($bare, false) . '}';
            } else {
                $piece = self::operatorPiece($kid, $forms[$index], $inFence) ?? self::w($kid, $inFence);
            }
            if ($piece === '') {
                continue;
            }
            if ($color !== null && $color !== $current) {
                // `\color{c}\middle|` has no space after the switch, as TeX-born text; any other piece takes one.
                $piece = '\\color{' . $color . '}' . ($bare instanceof Middle ? '' : ' ') . $piece;
                $current = $color;
            }
            if ($bare instanceof Scripts && ($bare->preSub !== null || $bare->preSup !== null) && $previous instanceof Scripts) {
                // After scripts, a bare `{}_a X` would read as more scripts of the node before; a group of its own keeps them prescripts.
                $piece = '{' . $piece . '}';
            }
            $previous = $bare;
            // Numbers side by side would read as one number, so each after the first is braced.
            $isNumber = $bare instanceof Number;
            $pieces[] = $isNumber && $afterNumber ? '{' . $piece . '}' : $piece;
            $afterNumber = $isNumber;
            // A switch written with nothing after it and no space of its own is followed with none (`\color{red}\middle|`).
            $glued[count($pieces) - 1] = $kid instanceof TexSource && $kid->isColorSwitch() && $piece === $kid->tex && ! str_ends_with($piece, ' ');
        }
        $out = '';
        foreach ($pieces as $i => $piece) {
            $out .= ($i > 0 && ! ($glued[$i - 1] ?? false) ? ' ' : '') . $piece;
        }

        return $out;
    }

    /**
     * Where a run of block-colored children, to the end of the row, starts when a Middle is in it, or null. That run is
     * TeX's `\color{c}\middle| ...`, the one way to color a \middle (`\textcolor{c}{\middle|}` is a plain bar in TeX),
     * and a later color change is another switch. An uncolored child after a colored Middle breaks the run (a switch
     * would color it too), and the color stays around each child, as a plain bar.
     *
     * @param list<Node> $children
     */
    private static function colorSwitchAt(array $children): ?int
    {
        $start = count($children);
        while ($start > 0 && ($c = self::strip($children[$start - 1])) instanceof Color && ! $c->inline) {
            $start--;
        }
        for ($i = $start; $i < count($children); $i++) {
            $c = self::strip($children[$i]);
            $m = $c instanceof Color ? self::strip($c->content) : null;
            if ($m instanceof Middle && TexSymbols::delimiter($m->char) !== null) {
                return $start;
            }
        }

        return null;
    }

    /** The row the reader builds for `\bmod`, `\mod{n}`, `\pmod{n}` or `\pod{n}`, written as that command; null for any other row. */
    private static function modular(Row $r): ?string
    {
        $c = array_map(self::strip(...), $r->children);
        $space = static fn (Node $n, float $em): bool => $n instanceof Space && abs($n->em - $em) < 1e-9;
        $mod = static fn (Node $n): bool => $n instanceof Identifier && $n->upright && $n->name === 'mod';
        $paren = static fn (Node $n, string $ch): bool => $n instanceof Operator && $n->char === $ch && $n->lspace === null && $n->rspace === null
            && $n->form === null && $n->stretchy === null && $n->minSize === null && $n->maxSize === null;
        $n = count($c);
        if ($n === 3 && $space($c[0], 4 / 18) && $mod($c[1]) && $space($c[2], 4 / 18)) {
            return '\bmod';
        }
        if ($n === 4 && $space($c[0], 1.0) && $mod($c[1]) && $space($c[2], 6 / 18)) {
            return '\mod{' . self::w($c[3], false) . '}';
        }
        if ($n === 6 && $space($c[0], 1.0) && $paren($c[1], '(') && $mod($c[2]) && $space($c[3], 6 / 18) && $paren($c[5], ')')) {
            return '\pmod{' . self::w($c[4], false) . '}';
        }
        if ($n === 4 && $space($c[0], 1.0) && $paren($c[1], '(') && $paren($c[3], ')')) {
            return '\pod{' . self::w($c[2], false) . '}';
        }

        return null;
    }

    private static function source(TexSource $s, bool $inFence): string
    {
        if ($s->close === null) {
            return $s->tex;
        }
        if (str_starts_with($s->tex, '\\tag')) {
            // KaTeX accepts an equation number only at the top of a formula.
            return isset(self::$topLevel[spl_object_id($s)]) ? $s->tex : '';
        }
        $inner = self::strip($s->content);
        if ($inner instanceof Color) {
            // The head keeps the color's spelling, so only what the color holds is written after it.
            $inner = $inner->content;
            $content = $inner;
        } else {
            $content = $s->content;
        }
        $body = str_starts_with($s->tex, '\\fbox') && $inner instanceof Text ? self::textEscape($inner->text) : self::w($content, $inFence && $s->close === '');

        // A switch with nothing after it (the rest of its fence follows as siblings) gives up its padding to the row's own space.
        $head = $body === '' && $s->close === '' ? rtrim($s->tex, ' ') : $s->tex;

        return $head . $body . $s->close;
    }

    /** The node with Fallback wrappers peeled. */
    private static function strip(Node $n): Node
    {
        while ($n instanceof Fallback && $n->content !== null) {
            $n = $n->content;
        }

        return $n;
    }

    /** The wrappers of a colored or source-spelled operator, around the operator with its class spacing dropped; null when no operator is inside. */
    private static function withPlainSymbol(Node $n): ?Node
    {
        $n = self::peel($n);
        if ($n instanceof Operator) {
            return new Operator($n->char);
        }
        if ($n instanceof Identifier) {
            return $n;
        }
        if ($n instanceof Color) {
            $inner = self::withPlainSymbol($n->content);

            return $inner === null ? null : new Color($inner, $n->color, $n->inline);
        }
        if ($n instanceof DisplayStyle) {
            $inner = self::withPlainSymbol($n->content);

            return $inner === null ? null : new DisplayStyle($inner, $n->display);
        }
        if ($n instanceof TexSource && $n->close !== null) {
            $inner = self::withPlainSymbol($n->content);

            return $inner === null ? null : new TexSource($inner, $n->tex, $n->close);
        }

        return null;
    }

    /** The node with Fallback wrappers and one-child Rows peeled. */
    private static function peel(Node $n): Node
    {
        while (true) {
            $n = self::strip($n);
            if ($n instanceof Row && count($n->children) === 1) {
                $n = $n->children[0];
            } else {
                return $n;
            }
        }
    }

    // ---- leaves ----

    /** Digits and punctuation as TeX reads them; anything TeX would read as markup is escaped. */
    private static function escapeAll(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $ch) {
            $out .= self::MATH_ESCAPES[$ch] ?? $ch;
            if (isset(self::MATH_ESCAPES[$ch]) && ctype_alpha(substr(self::MATH_ESCAPES[$ch], -1))) {
                $out .= ' ';
            }
        }

        return rtrim($out);
    }

    private static function textEscape(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $ch) {
            $out .= self::TEXT_ESCAPES[$ch] ?? $ch;
        }

        return $out;
    }

    /** One symbol: its command, or the character (escaped where TeX needs it). */
    private static function symbol(string $char): string
    {
        if ($char === '−') {
            return '-';
        }
        // What \not builds for a symbol with no negated form: the symbol and a combining slash.
        if (mb_strlen($char) === 2 && str_ends_with($char, "\u{0338}")) {
            return '\not' . self::symbol(mb_substr($char, 0, 1));
        }
        if ($char === ']' && self::$optional > 0) {
            return '\rbrack';
        }
        $cmd = TexSymbols::command($char);
        if ($cmd !== null) {
            return $cmd;
        }
        if (isset(self::MULTI_PRIMES[$char])) {
            return str_repeat('\prime', self::MULTI_PRIMES[$char]);
        }
        if (($negated = TexSymbols::negation($char)) !== null) {
            return '\not' . $negated;
        }

        return mb_strlen($char) > 1 ? self::functionName($char) : (self::MATH_ESCAPES[$char] ?? $char);
    }

    /** A multi-letter name: a standard function's command, otherwise \operatorname. */
    private static function functionName(string $name): string
    {
        if (TexSymbols::isFunctionCommand($name)) {
            return '\\' . $name;
        }
        return '\operatorname{' . self::operatorNameBody($name) . '}';
    }

    private static function operatorNameBody(string $name): string
    {
        $inner = [];
        foreach (mb_str_split($name) as $ch) {
            $inner[] = TexSymbols::command($ch) ?? (self::MATH_ESCAPES[$ch] ?? $ch);
        }

        return self::cat($inner);
    }

    /**
     * Pieces run together, with a space where a command would swallow the letter after it.
     *
     * @param list<string> $pieces
     */
    private static function cat(array $pieces): string
    {
        $out = '';
        foreach ($pieces as $p) {
            if ($out !== '' && (preg_match('/\\\\[a-zA-Z]+$/', $out) === 1 && preg_match('/^[a-zA-Z]/', $p) === 1 || preg_match('/\\\\char"[0-9A-F]+$/', $out) === 1 && preg_match('/^[0-9A-Fa-f]/', $p) === 1)) {
                $out .= ' ';
            }
            $out .= $p;
        }

        return $out;
    }

    private static function identifier(Identifier $i): string
    {
        $chars = mb_str_split($i->name);
        if ($chars === []) {
            return '';
        }
        if (! $i->upright) {
            if (count($chars) > 1) {
                return '\mathit{' . self::cat(array_map(self::letter(...), $chars)) . '}';
            }

            return self::italicSingle($chars[0]);
        }
        $starred = $i->function && $i->movableLimits === true && array_filter($chars, static fn (string $ch): bool => TexSymbols::unstyle($ch) !== null) === [];
        if ($starred && count($chars) === 1) {
            return '\\operatorname*{' . self::operatorNameBody($i->name) . '}'; // one letter too: `\operatorname*{E}` moves its limits
        }
        if (count($chars) === 1) {
            return self::uprightSingle($chars[0]);
        }
        if ($i->function && TexSymbols::isFunctionCommand($i->name)) {
            return '\\' . $i->name;
        }
        if ($starred) {
            return '\\operatorname*{' . self::operatorNameBody($i->name) . '}';
        }
        // Runs of one math variant (or none) side by side.
        $runs = [];
        foreach ($chars as $ch) {
            $u = TexSymbols::unstyle($ch);
            $variant = $u[0] ?? null;
            $plain = $u[1] ?? $ch;
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][0] === $variant) {
                $runs[$last][1][] = $plain;
            } else {
                $runs[] = [$variant, [$plain]];
            }
        }
        $runs = self::foldDigits($runs);
        $out = [];
        foreach ($runs as [$variant, $run]) {
            if ($variant !== null) {
                $out[] = '\\' . TexSymbols::VARIANT_WRITE[$variant] . '{' . self::cat(array_map(self::letter(...), $run)) . '}';
            } elseif (count($run) === 1) {
                $out[] = self::uprightSingle($run[0]);
            } elseif ($i->function) {
                $out[] = self::functionName(implode('', $run));
            } else {
                $out[] = '\\mathrm{' . self::cat(array_map(self::letter(...), $run)) . '}';
            }
        }

        return implode(' ', $out);
    }

    /**
     * Digits and punctuation that no font command restyles (`\mathcal{Ab1}`: the 1 stays 1) join the font group beside them, so a
     * name set in one font group reads back as one identifier. A run of letters never joins one.
     *
     * @param list<array{?string, list<string>}> $runs
     *
     * @return list<array{?string, list<string>}>
     */
    private static function foldDigits(array $runs): array
    {
        $plain = static fn (array $r): bool => $r[0] === null && preg_match('/^[^\p{L}]+$/u', implode('', $r[1])) === 1;
        $out = [];
        foreach ($runs as $run) {
            $prev = array_key_last($out);
            if ($plain($run) && $prev !== null && $out[$prev][0] !== null) {
                array_push($out[$prev][1], ...$run[1]);
            } elseif ($run[0] !== null && $prev !== null && $plain($out[$prev]) && $prev === 0) {
                // Leading plain characters join the group that follows them.
                $out[$prev] = [$run[0], [...$out[$prev][1], ...$run[1]]];
            } elseif ($run[0] !== null && $prev !== null && $out[$prev][0] === $run[0]) {
                array_push($out[$prev][1], ...$run[1]);
            } else {
                $out[] = $run;
            }
        }

        return $out;
    }

    /** A character inside a font group: its command if it has one, else itself. */
    private static function letter(string $ch): string
    {
        return TexSymbols::command($ch) ?? (self::MATH_ESCAPES[$ch] ?? $ch);
    }

    private static function italicSingle(string $ch): string
    {
        $u = TexSymbols::unstyle($ch);
        $cmd = TexSymbols::command($ch);
        // \Re and \Im are the fraktur capitals of Unicode's gaps: they have commands of their own, which read back as the same symbol.
        if ($u !== null && $cmd === null) {
            return '\\' . TexSymbols::VARIANT_WRITE[$u[0]] . '{' . self::letter($u[1]) . '}';
        }
        if ($cmd !== null && preg_match('/^[\x{391}-\x{3A9}]$/u', $ch) === 1) {
            return '\var' . substr($cmd, 1); // an italic capital Greek letter: \varGamma
        }

        return $cmd ?? (self::MATH_ESCAPES[$ch] ?? $ch);
    }

    private static function uprightSingle(string $ch): string
    {
        $u = TexSymbols::unstyle($ch);
        if ($u !== null) {
            return '\\' . TexSymbols::VARIANT_WRITE[$u[0]] . '{' . self::letter($u[1]) . '}';
        }
        $cmd = TexSymbols::command($ch);
        if ($cmd === null && isset(self::MULTI_PRIMES[$ch])) {
            return str_repeat('\prime', self::MULTI_PRIMES[$ch]);
        }
        if ($cmd === null && ($negated = TexSymbols::negation($ch)) !== null) {
            return '\not' . $negated;
        }
        $letterLike = preg_match('/^[\p{L}\p{N}]$/u', $ch) === 1;
        if ($cmd !== null && (! $letterLike || self::isOrdinaryCommand($ch) || preg_match('/^[\x{391}-\x{3A9}]$/u', $ch) === 1)) {
            return $cmd; // \infty, \Gamma: upright by themselves
        }
        if ($letterLike) {
            // Nearest TeX for upright lowercase Greek: \mathrm{\alpha} (there is no \upalpha).
            return '\mathrm{' . ($cmd ?? $ch) . '}';
        }

        return self::MATH_ESCAPES[$ch] ?? $ch;
    }

    /** A letter-like character TeX sets upright already (\ell, \hbar, \imath ...). */
    private static function isOrdinaryCommand(string $ch): bool
    {
        return in_array($ch, TexSymbols::ORD, true);
    }

    private static function space(float $em): string
    {
        foreach ([[3 / 18, '\,'], [4 / 18, '\:'], [5 / 18, '\;'], [-3 / 18, '\!'], [1 / 3, '\ '], [1.0, '\quad'], [2.0, '\qquad']] as [$v, $cmd]) {
            if (abs($em - $v) < 1e-9) {
                return $cmd;
            }
        }
        $num = rtrim(rtrim(number_format($em, 6, '.', ''), '0'), '.');

        return '\hspace{' . ($num === '' || $num === '-0' ? '0' : $num) . 'em}';
    }

    // ---- operators ----

    private static function operator(Operator $o): string
    {
        // A sized glyph with no \big spelling (a corner) is written plain: the size is dropped, as the nearest TeX.
        $size = $o->minSize ?? $o->maxSize;
        if ($size !== null) {
            $delim = TexSymbols::delimiter($o->char);
            if ($delim !== null && $delim !== '.') {
                $best = 0;
                foreach (Math::BIG_SIZES as $k => $s) {
                    if (abs($s - $size) < abs(Math::BIG_SIZES[$best] - $size)) {
                        $best = $k;
                    }
                }
                $side = OperatorDictionary::isOpening($o->char) ? 'l' : (OperatorDictionary::isClosing($o->char) ? 'r' : '');

                $big = '\\' . TexSymbols::BIG_COMMANDS[$best] . $side . ($delim === ']' && self::$optional > 0 ? '\rbrack' : $delim);

                // A braced one, `{\big(}`, is an ordinary atom: no spacing either side.
                return $o->lspace !== null && $o->rspace !== null && abs($o->lspace) < 1e-6 && abs($o->rspace) < 1e-6 ? '{' . $big . '}' : $big;
            }
        }
        $sym = self::symbol($o->char);
        // Only explicit spacing counts. A form alone is MathML-only and is dropped.
        $core = $o === self::$edge;
        [$class, $left, $right] = self::spacingPlan($o, self::$form, $core && self::$edgeLimits, false);
        if ($class !== null) {
            return '\\math' . $class . '{' . $sym . '}';
        }

        // The core of scripts or limits gets its kerns from the caller, outside the scripts, so a control stays next to the operator.
        return $core ? $sym : self::kern($left, true) . $sym . self::kern($right, false);
    }

    // The three statics below are context for operator(), set only around one self::w() or self::base() call and restored in a
    // finally block, so they never outlive it. $edge is matched by object identity, so only that one operator (never one in a
    // script or a nested row) is written bare; $form is set only for a row child that is itself an operator.

    /**
     * An operator child of a row (possibly colored), written bare with its kerns outside any color group, so the group does not
     * change the spacing around it. A letter whose spacing is not an identifier's is an op atom, since a bare letter reads back as
     * an identifier. Null when the child is no operator, or needs nothing the plain writing does not give.
     */
    private static function operatorPiece(Node $kid, ?string $form, bool $inFence): ?string
    {
        $core = UnderOver::nameNode($kid);
        if (! $core instanceof Operator) {
            return null;
        }
        $piece = self::withEdge($core, false, static function () use ($kid, $inFence, $form): string {
            $outer = self::$form;
            self::$form = $form;
            try {
                return self::w($kid, $inFence);
            } finally {
                self::$form = $outer;
            }
        });
        if (self::spacingPlan($core, $form, false, false)[0] !== null) {
            return $piece; // written as a class, which carries the spacing itself
        }
        $letter = preg_match('/^\p{L}$/u', $core->char) === 1 && ! $core->isLargeop(OperatorDictionary::FORM_PREFIX);
        if ($letter) {
            $dict = OperatorDictionary::lookup($core->char, $core->form ?? $form ?? OperatorDictionary::FORM_PREFIX);
            if (abs($core->lspace ?? $dict['lspace']) > self::SPACE_TOLERANCE || abs($core->rspace ?? $dict['rspace']) > self::SPACE_TOLERANCE) {
                [, $l, $r] = self::spacingPlan($core, $form, false, true);

                return self::kern($l, true) . '\mathop{' . $piece . '}' . self::kern($r, false);
            }

            return $piece;
        }
        [, $l, $r] = self::spacingPlan($core, $form, false, false);

        return self::kern($l, true) . $piece . self::kern($r, false);
    }

    /**
     * Spacing within this many mu is the same spacing: MathML writes em to four decimals (0.2778em is 5.0004mu), and other tools
     * write three, so a difference below it is rounding, not a kern.
     */
    private const float SPACE_TOLERANCE = 0.01;

    /**
     * Runs $write with $edge and $edgeLimits set, and puts the previous values back whatever happens.
     *
     * @param \Closure(): string $write
     */
    private static function withEdge(?Operator $edge, bool $limits, \Closure $write): string
    {
        [$outerEdge, $outerLimits] = [self::$edge, self::$edgeLimits];
        [self::$edge, self::$edgeLimits] = [$edge, $limits];
        try {
            return $write();
        } finally {
            [self::$edge, self::$edgeLimits] = [$outerEdge, $outerLimits];
        }
    }

    /** The operator whose scripts or limits are being written: its kerns go around them, not around the symbol. */
    private static ?Operator $edge = null;

    /** Whether $edge takes limits, so a class that would lose them is not used. */
    private static bool $edgeLimits = false;

    /** The form the row's position gives the operator being written, when it sits in a row; an explicit form is not part of it. */
    private static ?string $form = null;

    /**
     * How an operator's explicit spacing is written: an atom class when one gives the pair, else the difference from the spacing
     * TeX gives the bare symbol (the dictionary's, or an op atom's 1 mu a side when written inside \mathop) as kerns.
     * A side the source leaves out is the dictionary's. A limits base takes no class that would lose its limits.
     *
     * @return array{?string, float, float} class, left kern in mu, right kern in mu
     */
    private static function spacingPlan(Operator $o, ?string $form, bool $limits, bool $opAtom): array
    {
        // With spacing set, an explicit form changes only the node's own spacing: the reader gives the written symbol the form its
        // position ($form, prefix for a script base) implies. A form alone is not written (the reader itself sets one in places
        // such as an aligned cell), so it never calls for a kern.
        $written = $form ?? OperatorDictionary::FORM_PREFIX;
        $own = $o->form ?? $written;
        if ($o->lspace === null && $o->rspace === null && ! $opAtom) {
            return [null, 0.0, 0.0];
        }
        $dictOwn = OperatorDictionary::lookup($o->char, $own);
        $dict = OperatorDictionary::lookup($o->char, $written);
        $l = $o->lspace ?? $dictOwn['lspace'];
        $r = $o->rspace ?? $dictOwn['rspace'];
        if (! $opAtom) {
            if (($o->lspace === null || $o->rspace === null) && abs($l - $dict['lspace']) < self::SPACE_TOLERANCE && abs($r - $dict['rspace']) < self::SPACE_TOLERANCE) {
                return [null, 0.0, 0.0];
            }
            $class = self::classOf($l, $r, $o->char);
            if ($class === 'op' && $o->isLargeop(OperatorDictionary::FORM_PREFIX)) {
                $class = null; // the reader drops \mathop around a large operator it already takes as one, and with it the spacing
            }
            if ($class !== null && (! $limits || ($class === 'op' && ! $o->isLargeop(OperatorDictionary::FORM_PREFIX)))) {
                return [$class, 0.0, 0.0];
            }
        }
        [$bl, $br] = $opAtom ? [1.0, 1.0] : [(float) $dict['lspace'], (float) $dict['rspace']];

        return [null, $l - $bl, $r - $br];
    }

    /** `\mkern<n>mu` for a nonzero amount, with a space on the side facing the symbol; '' for none. */
    private static function kern(float $mu, bool $before): string
    {
        if (abs($mu) < self::SPACE_TOLERANCE) {
            return '';
        }
        $num = rtrim(rtrim(number_format($mu, 4, '.', ''), '0'), '.');
        $k = '\\mkern' . $num . 'mu';

        return $before ? $k . ' ' : ' ' . $k;
    }

    /**
     * The kerns to write before and after a core operator and its scripts or limits.
     *
     * @return array{string, string}
     */
    private static function edgeKerns(Operator $core, bool $limits, bool $opAtom): array
    {
        // A script or limits base is laid out as an operator first in its row (prefix), and so is the base read back from TeX,
        // so no row form applies here.
        [, $l, $r] = self::spacingPlan($core, null, $limits, $opAtom);

        return [self::kern($l, true), self::kern($r, false)];
    }

    /** The TeX atom class that sets this much space before and after, or null when none does. */
    private static function classOf(float|int $l, float|int $r, string $char): ?string
    {
        foreach (TexClass::SPACING as $name => [$cl, $cr]) {
            if (abs($cl - $l) > self::SPACE_TOLERANCE || abs($cr - $r) > self::SPACE_TOLERANCE) {
                continue;
            }
            if ($name === 'open' || $name === 'close' || $name === 'ord') {
                // 0/0 is shared: fences are open or close, anything else ordinary.
                return OperatorDictionary::isOpening($char) ? 'open' : (OperatorDictionary::isClosing($char) ? 'close' : 'ord');
            }

            return $name;
        }

        return null;
    }

    // ---- structures ----

    private static function root(Root $r): string
    {
        $body = '{' . self::w($r->radicand, false) . '}';

        return $r->index === null ? '\sqrt' . $body : '\sqrt[' . self::optional($r->index) . ']' . $body;
    }

    /** Greater than 0 while an optional `[...]` argument is written: a bare `]` would end it, so it is written \rbrack. */
    private static int $optional = 0;

    /** The body of a `[...]` argument. */
    private static function optional(Node $n): string
    {
        self::$optional++;
        try {
            $s = self::w($n, false);
        } finally {
            self::$optional--;
        }

        // KaTeX ends the argument at the first `]` it sees, so a label holding one (a nested `[..]`) is braced; it also misreads a
        // style group first in the argument and followed by more (`[{\displaystyle a}{b}]`). A lone `]` symbol is written \rbrack,
        // so bracing never turns it into a braced (ordinary) atom.
        return str_contains($s, ']') || preg_match('/^\{\\\\(?:display|text|script|scriptscript)style /', $s) === 1 ? '{' . $s . '}' : $s;
    }

    /** A script argument: bare when it is one token, braced otherwise. */
    private static function arg(Node $n): string
    {
        $s = self::w($n, false);

        if (preg_match('/^\\\\([a-zA-Z]+)$/', $s, $m) === 1) {
            // KaTeX rejects a function or macro command (\sin, \neq, \int) as an unbraced script argument: brace all but plain symbols.
            return isset(TexSymbols::GREEK[$m[1]]) || isset(TexSymbols::GREEK_UP[$m[1]]) || in_array($m[1], self::BARE_SCRIPT_SYMBOLS, true) ? $s : '{' . $s . '}';
        }

        return preg_match('/^[^\\\\\s{}]$/u', $s) === 1 ? $s : '{' . $s . '}';
    }

    /**
     * How the limits of an operator or limit name behave: 'beside' (scripts), 'movable' (stacked in display style, beside inline)
     * or 'stacked' (in every style). Null for anything else.
     */
    private static function limitsState(Node $n): ?string
    {
        $n = UnderOver::nameNode($n);
        if ($n instanceof Scripts) {
            return 'beside';
        }
        if (! $n instanceof UnderOver) {
            return null;
        }

        return UnderOver::isLimitName($n->base) || UnderOver::operatorOf($n->base)?->hasMovableLimits(OperatorDictionary::FORM_PREFIX) === true ? 'movable' : 'stacked';
    }

    /**
     * The one rule for the limits of an operator or limit name: write the base, ask the reader how that spelling takes its scripts with
     * no control after it, and add \nolimits, \limits or \displaylimits when that differs from $state. A control follows a bare
     * operator, so a color (or other wrapper) goes inside \mathop{...}. Null when the base is neither an operator that takes limits
     * nor a limit name.
     *
     * @return ?array{string, bool, string, string} the base with its control, whether a control was added, and the kerns to write
     *                                              before the base and after its scripts
     */
    private static function limitsBase(Node $base, string $state): ?array
    {
        $core = UnderOver::nameNode($base);
        if ($core instanceof Operator) {
            // A class wrapper (`\mathrel{\sum}`) keeps the spacing as the author wrote it, when its spelling takes the limits as asked.
            $plain = self::spacingPlan($core, null, false, false)[0];
            if ($plain !== null && $plain !== self::spacingPlan($core, null, true, false)[0]) {
                $classed = self::limitsSpelling($base, $state, true);
                // Only with no control: a limits control after a class atom (`\mathrel{\sum}\limits`) is not legal TeX.
                if ($classed !== null && ! $classed[1] && ! $classed[2] && self::limitsStateOf($classed[0]) === $state) {
                    return [$classed[0], $classed[1], '', ''];
                }
            }
        }
        $r = self::limitsSpelling($base, $state, false);
        if ($r === null) {
            return null;
        }
        // A wrapper class around the whole (`\mathrel{\mathop{x}_a}`) would give the exact spacing too, but the reader takes a class
        // only on a single symbol, so it would not read back; the kerns do.
        [$left, $right] = $core instanceof Operator ? self::edgeKerns($core, true, $r[2]) : ['', ''];

        return [$r[0], $r[1], $left, $right];
    }

    /** The limits state of a spelling followed by a subscript, or null when unknown. */
    private static function limitsStateOf(string $spelled): ?string
    {
        // The probe is pure, and a long formula spells the same few bases again and again.
        if (array_key_exists($spelled, self::$probeMemo)) {
            return self::$probeMemo[$spelled];
        }
        if (count(self::$probeMemo) >= self::PROBE_MEMO_SIZE) {
            self::$probeMemo = [];
        }
        $state = self::probeLimitsState($spelled);
        self::$probeMemo[$spelled] = $state;

        return $state;
    }

    /** Most spellings the probe memo holds; it starts over when full. */
    private const int PROBE_MEMO_SIZE = 256;

    /** @var array<string, ?string> */
    private static array $probeMemo = [];

    private static function probeLimitsState(string $spelled): ?string
    {
        $probe = TexReader::readUpTo($spelled . '_a', TexReader::MAX_DEPTH);
        foreach ($probe->notes() as $note) {
            if ($note->reason === TexReader::NESTED_TOO_DEEPLY) {
                return null;
            }
        }

        return self::limitsState($probe->node);
    }

    /**
     * @param bool $classAllowed write a class spelling the limits would lose (`\mathrel{\sum}`) as it is
     * @return ?array{string, bool, bool} the spelling, whether a control was added, and whether a non-large operator sits in an
     *                                    \mathop that gives it an op atom's spacing
     */
    private static function limitsSpelling(Node $base, string $state, bool $classAllowed): ?array
    {
        $core = UnderOver::nameNode($base);
        if (! $core instanceof Operator && ! $core instanceof Identifier) {
            return null; // nothing to ask of a spelling: and never write a subtree here that the caller writes again
        }
        $spelled = self::withEdge($core instanceof Operator ? $core : null, ! $classAllowed, static fn (): string => self::w($base, false));
        $small = $core instanceof Operator && ! $core->isLargeop(OperatorDictionary::FORM_PREFIX);
        $opAtom = $small && self::spacingPlan($core, null, true, false)[0] === 'op';
        $letter = $core instanceof Operator && preg_match('/^\p{L}$/u', $core->char) === 1;
        $movesAsOne = $core instanceof Operator && ($core->movablelimits === true || $letter) && mb_strlen($core->char) === 1;
        $plainSymbol = $core instanceof Operator && ! $core->isLargeop(OperatorDictionary::FORM_PREFIX) && ! OperatorDictionary::lookup($core->char, OperatorDictionary::FORM_PREFIX)['movablelimits'];
        if ($movesAsOne && $plainSymbol && ! str_contains($spelled, '\mathop{')) {
            // A control follows an operator: a plain symbol that moves its limits (a lone `<mo movablelimits>`) is made one, and so is
            // a letter, which written bare would read back as an identifier with an identifier's spacing.
            $spelled = '\mathop{' . $spelled . '}';
            $opAtom = true;
        }
        $relevant = match (true) {
            $core instanceof Operator => $core->isLargeop(OperatorDictionary::FORM_PREFIX) || $core->hasMovableLimits(OperatorDictionary::FORM_PREFIX) || str_contains($spelled, '\mathop{'),
            default => $core->function && $core->upright && ($core->movableLimits !== null || OperatorDictionary::isLimitName($core->name) || TexSymbols::isFunctionCommand($core->name)),
        };
        if (! $relevant) {
            return null;
        }
        if ($core instanceof Identifier && self::peel($base) === $core && preg_match('~^\\\\(?:operatorname\*?|mathop)\{|^\\\\[A-Za-z]+$~', $spelled) !== 1) {
            // A control follows an operator, not a math-alphabet group (`\mathbf{max}`): the name is made one.
            $spelled = '\mathop{' . $spelled . '}';
        }
        $default = self::limitsStateOf($spelled);
        if ($default === null) {
            return null;
        }
        if ($default === $state) {
            return [$spelled, false, $opAtom];
        }
        $wrapped = self::peel($base) !== $core;
        if ($wrapped && $state === 'beside') {
            // A group is an ordinary atom, so its scripts sit beside it: `{\textcolor{red}{\sum}}_i`, with no control after a wrapper.
            return ['{' . $spelled . '}', true, $opAtom];
        }
        $control = ['beside' => '\nolimits', 'stacked' => '\limits', 'movable' => '\displaylimits'][$state];
        if (! $wrapped) {
            return [$spelled . $control, true, $opAtom];
        }
        $inner = self::withPlainSymbol($base);

        return $inner === null ? null : ['\mathop{' . self::w($inner, false) . '}' . $control, true, $small];
    }

    private static function scriptParts(?Node $sub, ?Node $sup): string
    {
        return ($sub === null ? '' : '_' . self::arg($sub)) . ($sup === null ? '' : '^' . self::arg($sup));
    }

    /** A script base: braced unless it is a single atom. */
    private static function base(Node $n): string
    {
        $s = self::w($n, false);
        $p = self::peel($n);
        $atom = match (true) {
            $p instanceof Identifier, $p instanceof Operator, $p instanceof Fraction, $p instanceof Root, $p instanceof Text => true,
            // A fence is one atom only when written \left..\right (or as a matrix); a plain one is several.
            $p instanceof Fenced => TexSymbols::delimiter($p->open) !== null && TexSymbols::delimiter($p->close) !== null,
            $p instanceof Number => mb_strlen($p->text) === 1,
            $p instanceof UnderOver => self::$underOverAtom[$p] ?? self::underOver($p)[1],
            default => false,
        };
        // A mixed-variant identifier is several groups side by side: not one atom.
        if ($atom && ! $p instanceof Fenced && self::hasTopLevelSpace($s)) {
            $atom = false;
        }

        // `\textcolor{red}{x}^2` takes its script on the token it colors, so a lone colored token needs no braces.
        $token = $atom ? null : self::loneColoredToken($p);
        if ($token !== null && $s !== '' && ! self::hasTopLevelSpace(self::w($token, false))) {
            return $s;
        }

        return $atom && $s !== '' ? $s : '{' . $s . '}';
    }

    /** The one token a `\textcolor` around $p colors, unless it is a large operator with movable limits (which would take its scripts as limits). */
    private static function loneColoredToken(Node $p): ?Node
    {
        if ($p instanceof TexSource && $p->close === '}' && str_starts_with($p->tex, '\\textcolor')) {
            $p = self::strip($p->content);
        }
        if (! $p instanceof Color || $p->inline) {
            return null;
        }
        $t = self::peel($p->content);

        return match (true) {
            $t instanceof Identifier => $t,
            $t instanceof Number => mb_strlen($t->text) === 1 ? $t : null,
            $t instanceof Operator => $t->isLargeop(OperatorDictionary::FORM_PREFIX) && $t->hasMovableLimits(OperatorDictionary::FORM_PREFIX) ? null : $t,
            default => null,
        };
    }

    private static function hasTopLevelSpace(string $s): bool
    {
        $depth = 0;
        $clean = preg_replace('/\\\\[{}]/', '', $s) ?? $s;
        foreach (str_split($clean) as $c) {
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            } elseif ($c === ' ' && $depth === 0) {
                return true;
            }
        }

        return false;
    }

    private static function scripts(Scripts $s): string
    {
        if ($s->preSub !== null || $s->preSup !== null) {
            // The empty group gives the prescripts something to attach to; the base then takes its own scripts as usual.
            $pre = '{}' . self::scriptParts($s->preSub, $s->preSup);
            $post = $s->sub === null && $s->sup === null ? self::base($s->base) : self::scripts(new Scripts($s->base, $s->sub, $s->sup));

            // A control word ends at a space, not at the letter of the base after it.
            return $pre . (preg_match('/\\\\[a-zA-Z]+$/', $pre) === 1 ? ' ' : '') . $post;
        }
        $limits = self::limitsBase($s->base, 'beside');
        $controlled = $limits !== null && $limits[1];
        $kernL = $kernR = '';
        if ($controlled) {
            [$base, , $kernL, $kernR] = $limits;
        } else {
            $core = UnderOver::nameNode($s->base);
            $base = self::withEdge($core instanceof Operator ? $core : null, false, static fn (): string => self::base($s->base));
            if ($core instanceof Operator) {
                [$kernL, $kernR] = self::edgeKerns($core, false, false);
            }
        }

        // Primes are written as apostrophes: KaTeX and LaTeX have no raw ″ ‴ ⁗.
        $sup = $s->sup;
        $primes = '';
        if ($sup !== null && ! $controlled) {
            $parts = $sup instanceof Row ? $sup->children : [$sup];
            $first = $parts[0] ?? null;
            if ($first instanceof Identifier && isset(self::MULTI_PRIMES[$first->name])) {
                $primes = str_repeat("'", self::MULTI_PRIMES[$first->name]);
                $rest = array_slice($parts, 1);
                $sup = $rest === [] ? null : (count($rest) === 1 ? $rest[0] : new Row($rest));
            }
        }

        if ($primes !== '' && $sup !== null) {
            // KaTeX takes a superscript after primes only straight after them: f'^2_a, not f'_a^2.
            return $kernL . $base . $primes . '^' . self::arg($sup) . self::scriptParts($s->sub, null) . $kernR;
        }

        return $kernL . $base . $primes . self::scriptParts($s->sub, $sup) . $kernR;
    }

    /** Prime characters TeX has no symbol for, and how many apostrophes make them. */
    private const array MULTI_PRIMES = ['″' => 2, '‴' => 3, '⁗' => 4];

    /** Whether the last spelling of each UnderOver is a single atom, so base() asks without writing it a second time. */
    /** @var \WeakMap<UnderOver, bool>|null */
    private static ?\WeakMap $underOverAtom = null;

    private static function writeUnderOver(UnderOver $u): string
    {
        [$tex, $atom] = self::underOver($u);
        self::$underOverAtom ??= new \WeakMap();
        self::$underOverAtom[$u] = $atom;

        return $tex;
    }

    /**
     * @return array{string, bool} the TeX, and whether it is a single atom (no braces needed before a script)
     */
    private static function underOver(UnderOver $u): array
    {
        $base = $u->base;
        $under = $u->under;
        $over = $u->over;
        $op = UnderOver::operatorOf($base);
        $plainFlags = ! $u->accent && ! $u->accentUnder;

        if ($plainFlags && ($state = self::limitsState($u)) !== null && ($limits = self::limitsBase($base, $state)) !== null) {
            return [$limits[2] . $limits[0] . self::scriptParts($under, $over) . $limits[3], false];
        }

        $overOp = UnderOver::operatorOf($over);
        $underOp = UnderOver::operatorOf($under);

        // \overbrace{x}^{label}, \underbrace{x}_{label}
        if ($under === null && $over !== null && ($inner = self::peel($base)) instanceof UnderOver && $inner->under === null && UnderOver::isBrace(UnderOver::operatorOf($inner->over), true)) {
            return ['\overbrace{' . self::w($inner->base, false) . '}^' . self::arg($over), false];
        }
        if ($over === null && $under !== null && ($inner = self::peel($base)) instanceof UnderOver && $inner->over === null && UnderOver::isBrace(UnderOver::operatorOf($inner->under), false)) {
            return ['\underbrace{' . self::w($inner->base, false) . '}_' . self::arg($under), false];
        }
        if ($under === null && $overOp !== null && UnderOver::isBrace($overOp, true)) {
            return ['\overbrace{' . self::w($base, false) . '}', false];
        }
        if ($over === null && $underOp !== null && UnderOver::isBrace($underOp, false)) {
            return ['\underbrace{' . self::w($base, false) . '}', false];
        }
        // Rules.
        if ($under === null && $overOp !== null && in_array($overOp->char, ["\u{00AF}", "\u{203E}"], true)) {
            return ['\overline{' . self::w($base, false) . '}', true];
        }
        if ($over === null && $underOp !== null && $u->accentUnder && in_array($underOp->char, ['_', "\u{00AF}", "\u{203E}"], true)) {
            return ['\underline{' . self::w($base, false) . '}', true];
        }
        // Accents.
        if ($u->accent && $under === null && $overOp !== null) {
            $mark = Styles::accentMark($overOp->char) ?? $overOp->char;
            $names = TexSymbols::ACCENT_WRITE[$mark] ?? null;
            if ($names !== null) {
                $wide = $overOp->stretchy !== false;
                $name = $wide ? ($names[1] ?? $names[0]) : ($names[0] ?? $names[1]);

                return ['\\' . $name . '{' . self::w($base, false) . '}', true];
            }
        }
        // Extensible arrows.
        if ($plainFlags && $op !== null && UnderOver::isStretchy($op) && mb_strlen($op->char) === 1 && isset(TexSymbols::XARROWS[$op->char]) && UnderOver::isArrow($op->char)) {
            $label = '';
            if ($under !== null) {
                $label = '[' . self::optional($under) . ']';
            }

            return ['\\' . TexSymbols::XARROWS[$op->char] . $label . '{' . ($over === null ? '' : self::w($over, false)) . '}', true];
        }
        // Anything else. An operator's kerns go outside the stack, not inside the base.
        $core = UnderOver::nameNode($base);
        $s = self::withEdge($core instanceof Operator ? $core : null, false, static fn (): string => self::w($base, false));
        [$kernL, $kernR] = $core instanceof Operator ? self::edgeKerns($core, false, false) : ['', ''];
        if (self::peel($base) instanceof UnderOver) {
            $s = '{' . $s . '}'; // a nested pair, not one node with both parts
        }
        if ($over !== null) {
            $s = '\overset{' . self::w($over, false) . '}{' . $s . '}';
        }
        if ($under !== null) {
            $s = '\underset{' . self::w($under, false) . '}{' . $s . '}';
        }

        return [$kernL . $s . $kernR, $kernL === '' && $kernR === ''];
    }

    private static function fenced(Fenced $f): string
    {
        $inner = self::peel($f->content);
        if ($f->open === '(' && $f->close === ')' && $inner instanceof Fraction && ! $inner->bar) {
            return '\binom{' . self::w($inner->numerator, false) . '}{' . self::w($inner->denominator, false) . '}';
        }
        if ($inner instanceof Grid && ! $inner->alignment) {
            $env = self::matrixEnvironment($f, $inner);
            if ($env !== null) {
                return self::environment($inner, $env);
            }
        }
        $open = TexSymbols::delimiter($f->open);
        $close = TexSymbols::delimiter($f->close);
        // \middle only works between \left and \right: in a plain fence the middles are plain symbols too.
        $content = self::colorSwitchContent($f->content);
        $body = $inner instanceof Grid ? self::gridBody($inner, false) : self::w($content, $open !== null && $close !== null);
        if ($open === null || $close === null) {
            // A fence glyph with no \left spelling (a corner): plain symbols around the content, so the glyphs stay.
            return implode(' ', array_filter([self::symbol($f->open), $body, self::symbol($f->close)], static fn (string $p): bool => $p !== ''));
        }

        return '\left' . $open . ($body === '' ? ' ' : ' ' . $body . ' ') . '\right' . $close;
    }

    /**
     * A block color that is the whole content of a fence and holds a Middle at its top level, as a row of colored children,
     * so the row writes it as a switch (`\color{c} a \middle| b`); TeX has no other way to color a \middle.
     */
    private static function colorSwitchContent(Node $content): Node
    {
        $c = self::strip($content);
        if (! $c instanceof Color || $c->inline) {
            return $content;
        }
        $row = self::strip($c->content);
        if (! $row instanceof Row) {
            return $content;
        }
        foreach ($row->children as $child) {
            $m = self::strip($child);
            if ($m instanceof Middle && TexSymbols::delimiter($m->char) !== null) {
                return new Row(array_map(static fn (Node $k): Node => new Color($k, $c->color), $row->children));
            }
        }

        return $content;
    }

    private static function matrixEnvironment(Fenced $f, Grid $g): ?string
    {
        $all = static fn (string $a): bool => count(array_unique($g->columnAlign)) <= 1 && ($g->columnAlign[0] ?? $a) === $a;
        if ($all('center')) {
            return match ([$f->open, $f->close]) {
                ['(', ')'] => 'pmatrix',
                ['[', ']'] => 'bmatrix',
                ['{', '}'] => 'Bmatrix',
                ['|', '|'] => 'vmatrix',
                ["\u{2016}", "\u{2016}"] => 'Vmatrix',
                default => null,
            };
        }
        if ($f->open === '{' && $f->close === '' && $all('left')) {
            return $g->display ? 'dcases' : 'cases';
        }

        return null;
    }

    /** A grid on its own (or inside a \left \right pair): a matrix when centered, aligned when an alignment, otherwise an array. */
    private static function gridBody(Grid $g, bool $bare): string
    {
        if ($g->alignment) {
            // No space between pairs is alignedat's: it reads back to the same tree.
            if ($g->columns > 2 && ($g->columnSpacing[1] ?? 2.0) === 0.0) {
                return self::environment($g, 'alignedat', '{' . intdiv($g->columns + 1, 2) . '}');
            }

            return self::environment($g, 'aligned');
        }
        // gathered has one column (amsmath rejects an & in it), so a wider display grid is a darray.
        if ($bare && ($g->columns === 0 || count(array_unique($g->columnAlign)) === 1 && $g->columnAlign[0] === 'center') && (! $g->display || $g->columns <= 1)) {
            return self::environment($g, $g->display ? 'gathered' : 'matrix');
        }
        $spec = implode('', array_map(static fn (string $a): string => $a[0], $g->columnAlign));

        return self::environment($g, $g->display ? 'darray' : 'array', '{' . $spec . '}');
    }

    private static function environment(Grid $g, string $env, string $args = ''): string
    {
        $rows = [];
        foreach ($g->rows as $row) {
            $cells = [];
            foreach ($row as $j => $cell) {
                $cells[] = self::trimTex(self::w($cell, false));
            }
            while (count($cells) > 1 && end($cells) === '') {
                array_pop($cells);
            }
            $line = '';
            foreach ($cells as $j => $c) {
                if ($j > 0) {
                    $line .= ($line === '' ? '' : ' ') . '&';
                }
                if ($c !== '') {
                    $line .= ($j > 0 && ! ($g->alignment && $j % 2 === 1) ? ' ' : '') . $c;
                }
            }
            $rows[] = self::trimTex($line);
        }

        return '\begin{' . $env . '}' . $args . implode(' \\\\ ', $rows) . '\end{' . $env . '}';
    }
}
