<?php

declare(strict_types=1);

namespace Phactorial;

/**
 * Color values for formulas: xcolor names, hex and the xcolor color models for TeX, and the CSS subset MathML accepts.
 * Every accepted value is normalized to lowercase #rrggbb; anything else is rejected with null.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final class Colors
{
    /** The xcolor base names (case-sensitive, as in xcolor) and their values. */
    private const XCOLOR = [
        'black' => '#000000', 'blue' => '#0000ff', 'brown' => '#bf8040', 'cyan' => '#00ffff',
        'darkgray' => '#404040', 'gray' => '#808080', 'green' => '#00ff00', 'lightgray' => '#bfbfbf',
        'lime' => '#bfff00', 'magenta' => '#ff00ff', 'olive' => '#808000', 'orange' => '#ff8000',
        'pink' => '#ffbfbf', 'purple' => '#bf0040', 'red' => '#ff0000', 'teal' => '#008080',
        'violet' => '#800080', 'white' => '#ffffff', 'yellow' => '#ffff00',
    ];

    /** The sixteen HTML 4 color names, with their CSS values. */
    private const CSS = [
        'black' => '#000000', 'silver' => '#c0c0c0', 'gray' => '#808080', 'white' => '#ffffff',
        'maroon' => '#800000', 'red' => '#ff0000', 'purple' => '#800080', 'fuchsia' => '#ff00ff',
        'green' => '#008000', 'lime' => '#00ff00', 'olive' => '#808000', 'yellow' => '#ffff00',
        'navy' => '#000080', 'blue' => '#0000ff', 'teal' => '#008080', 'aqua' => '#00ffff',
    ];

    /**
     * The xcolor base names in the model xcolor defines each one in, for mixing. Mixing happens in a color's own model,
     * so `cyan!50!red` (cmyk) and `red!50!cyan` (rgb) follow different paths, as in LaTeX.
     *
     * @var array<string, array{'rgb'|'cmyk'|'gray', list<int|float>}>
     */
    private const MODELS = [
        'red' => ['rgb', [1, 0, 0]], 'green' => ['rgb', [0, 1, 0]], 'blue' => ['rgb', [0, 0, 1]],
        'brown' => ['rgb', [.75, .5, .25]], 'lime' => ['rgb', [.75, 1, 0]], 'orange' => ['rgb', [1, .5, 0]],
        'pink' => ['rgb', [1, .75, .75]], 'purple' => ['rgb', [.75, 0, .25]], 'teal' => ['rgb', [0, .5, .5]],
        'violet' => ['rgb', [.5, 0, .5]],
        'cyan' => ['cmyk', [1, 0, 0, 0]], 'magenta' => ['cmyk', [0, 1, 0, 0]], 'yellow' => ['cmyk', [0, 0, 1, 0]],
        'olive' => ['cmyk', [0, 0, 1, .5]],
        'black' => ['gray', [0]], 'darkgray' => ['gray', [.25]], 'gray' => ['gray', [.5]],
        'lightgray' => ['gray', [.75]], 'white' => ['gray', [1]],
    ];

    /** A mixing percentage: digits with an optional fraction, or a bare fraction. */
    private const PERCENT = '/^(?:\d+(?:\.\d*)?|\.\d+)$/D';

    private const FRACTION = '(?:[01]|[01]?\.\d+|[01]\.)';

    private function __construct() {}

    /**
     * A TeX color as #rrggbb: an xcolor name or #rgb/#rrggbb when there is no model, otherwise a value in the model
     * (HTML, rgb, RGB or gray). Null when the spec does not match the grammar exactly.
     */
    public static function tex(string $spec, ?string $model = null): ?string
    {
        if ($model === null) {
            return self::XCOLOR[$spec] ?? self::hex($spec) ?? self::mix($spec);
        }

        return match ($model) {
            'HTML' => preg_match('/^[0-9A-Fa-f]{6}$/D', $spec) === 1 ? '#' . strtolower($spec) : null,
            'rgb' => self::channels($spec, 3, '/^' . self::FRACTION . '$/D', 255.0),
            'RGB' => self::channels($spec, 3, '/^\d{1,3}$/D', 1.0, 255),
            'gray' => self::channels($spec, 1, '/^' . self::FRACTION . '$/D', 255.0),
            default => null,
        };
    }

    /** A MathML color as #rrggbb: #rgb, #rrggbb or one of the sixteen HTML 4 names, in any case. Null otherwise. */
    public static function css(string $value): ?string
    {
        return self::hex($value) ?? self::CSS[strtolower($value)] ?? null;
    }

    /** The note for a color that was rejected. */
    public static function unknown(string $spec): string
    {
        return "unknown color `{$spec}`; drawn in the default color";
    }

    /** Whether $reason is a note made by unknown(). */
    public static function isUnknown(string $reason): bool
    {
        return str_starts_with($reason, 'unknown color `');
    }

    /** A rejected spelling made safe to show in a note: every character outside letters, digits, `#.,!:-` and space becomes `?`. */
    public static function masked(string $spec): string
    {
        return preg_replace('/[^A-Za-z0-9#.,!:\- ]/u', '?', $spec) ?? '';
    }

    /** Whether $color is exactly lowercase #rrggbb, the form every renderer and writer accepts for a display item. */
    public static function isHex(string $color): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/D', $color) === 1;
    }

    /**
     * An xcolor mixing expression over the base names as #rrggbb, computed as xcolor computes it: `red!50` (a tint
     * toward white), `red!30!blue`, chains, and leading minus signs for the complement of the whole result.
     * Null for anything outside that grammar, including a percentage above 100.
     */
    private static function mix(string $spec): ?string
    {
        $minus = strspn($spec, '-');
        $parts = explode('!', substr($spec, $minus));
        if (count($parts) % 2 === 0) {
            $parts[] = 'white';
        }
        $first = self::MODELS[$parts[0]] ?? null;
        if ($first === null) {
            return null;
        }
        [$model, $c] = self::convert($first[0], $first[1], $first[0]);
        for ($i = 1; $i < count($parts); $i += 2) {
            $next = self::MODELS[$parts[$i + 1]] ?? null;
            if ($next === null || preg_match(self::PERCENT, $parts[$i]) !== 1 || (float) $parts[$i] > 100) {
                return null;
            }
            // xcolor compares the percentage as a TeX dimension (65536sp to the point): at 0 the name replaces the
            // color in its own model, at 100 nothing changes, and only between them does a conversion happen.
            $sp = (int) round((float) $parts[$i] * 65536);
            if ($sp === 0) {
                [$model, $c] = self::convert($next[0], $next[1], $next[0]);
                continue;
            }
            if ($sp === 100 * 65536) {
                continue;
            }
            if ($model === 'gray' && $next[0] !== 'gray') {
                [$model, $c] = self::convert($model, $c, $next[0]);
            }
            $n = self::convert($next[0], $next[1], $model)[1];
            $p = (float) $parts[$i] / 100;
            foreach ($c as $k => $v) {
                $c[$k] = $p * $v + (1 - $p) * $n[$k];
            }
        }
        if ($minus % 2 === 1) {
            // xcolor complements cmyk through cmy (c + k, clipped), not channel by channel.
            $c = $model === 'cmyk'
                ? self::fromCmy([1 - min(1, $c[0] + $c[3]), 1 - min(1, $c[1] + $c[3]), 1 - min(1, $c[2] + $c[3])])
                : array_map(static fn (float $v): float => 1 - $v, $c);
        }
        $out = '#';
        foreach (self::convert($model, $c, 'rgb')[1] as $v) {
            $n = (int) round($v * 100000);
            $out .= sprintf('%02x', intdiv($n * 255 + 50000, 100000));
        }

        return $out;
    }

    /**
     * A color from one xcolor model in another, with xcolor's formulas. Only the conversions mixing needs: gray to rgb
     * or cmyk, cmyk to rgb, rgb to cmyk (undercolor removal and black generation at their defaults), and identity.
     *
     * @param 'rgb'|'cmyk'|'gray' $from
     * @param list<int|float> $c
     * @param 'rgb'|'cmyk'|'gray' $to
     * @return array{'rgb'|'cmyk'|'gray', list<float>}
     */
    private static function convert(string $from, array $c, string $to): array
    {
        $out = match ($from . '>' . $to) {
            'gray>rgb' => [$c[0], $c[0], $c[0]],
            'gray>cmyk' => [0.0, 0.0, 0.0, 1 - $c[0]],
            'cmyk>rgb' => [1 - min(1, $c[0] + $c[3]), 1 - min(1, $c[1] + $c[3]), 1 - min(1, $c[2] + $c[3])],
            'rgb>cmyk' => self::fromCmy([1 - $c[0], 1 - $c[1], 1 - $c[2]]),
            default => $c,
        };

        return [$to, array_map(floatval(...), $out)];
    }

    /**
     * cmy as cmyk: k is the smallest channel and comes out of the other three.
     *
     * @param list<float> $cmy
     * @return list<float>
     */
    private static function fromCmy(array $cmy): array
    {
        $k = min($cmy[0], $cmy[1], $cmy[2]);

        return [max(0, min(1, $cmy[0] - $k)), max(0, min(1, $cmy[1] - $k)), max(0, min(1, $cmy[2] - $k)), $k];
    }

    /** #rgb or #rrggbb, in any case, as lowercase #rrggbb. */
    private static function hex(string $s): ?string
    {
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/D', $s, $m) !== 1) {
            return null;
        }
        $h = strtolower($m[1]);
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }

        return '#' . $h;
    }

    /** Comma-separated channel values (a gray value is repeated over all three), scaled and rounded half away from zero. */
    private static function channels(string $spec, int $count, string $grammar, float $scale, ?int $max = null): ?string
    {
        $parts = explode(',', $spec);
        if (count($parts) !== $count) {
            return null;
        }
        $out = '#';
        foreach ($parts as $p) {
            $p = trim($p, ' ');
            if (preg_match($grammar, $p) !== 1) {
                return null;
            }
            $v = (float) $p;
            if ($max !== null ? $v > $max : $v > 1.0) {
                return null;
            }
            $out .= sprintf('%02x', (int) round($v * $scale));
        }

        return $count === 1 ? '#' . str_repeat(substr($out, 1), 3) : $out;
    }
}
