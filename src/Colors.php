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

    private const FRACTION = '(?:[01]|[01]?\.\d+|[01]\.)';

    private function __construct() {}

    /**
     * A TeX color as #rrggbb: an xcolor name or #rgb/#rrggbb when there is no model, otherwise a value in the model
     * (HTML, rgb, RGB or gray). Null when the spec does not match the grammar exactly.
     */
    public static function tex(string $spec, ?string $model = null): ?string
    {
        if ($model === null) {
            return self::XCOLOR[$spec] ?? self::hex($spec);
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
