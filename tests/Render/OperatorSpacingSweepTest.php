<?php

declare(strict_types=1);

namespace Phactorial\Tests\Render;

use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Render\TexWriter;
use PHPUnit\Framework\TestCase;

/**
 * Operator bases with explicit spacing, written as TeX, must keep their width and write legal limits controls.
 *
 * The inputs are every combination of base, color, wrapper, movablelimits and spacing below. The exact set that still fails
 * is pinned as a count and a short hash of the sorted inputs, so it can only change deliberately. To see the set, run with
 * `OPERATOR_SWEEP_DUMP=<file>`: it writes each failing input and the reason.
 */
final class OperatorSpacingSweepTest extends TestCase
{
    private const float TOLERANCE = 0.05;

    // [count, first 12 hex chars of sha1 of the sorted failing inputs joined by NUL]
    private const array PIN = [0, 'da39a3ee5e6b'];

    /** @return list<string> */
    private static function inputs(): array
    {
        $spacings = ['', ' lspace="0" rspace="0"', ' lspace="thinmathspace" rspace="thinmathspace"', ' lspace="mediummathspace" rspace="mediummathspace"', ' lspace="thickmathspace" rspace="thickmathspace"', ' lspace="verythickmathspace" rspace="verythickmathspace"', ' lspace="0.0555556em" rspace="0.0555556em"', ' lspace="0"', ' rspace="0"', ' rspace="thickmathspace"', ' lspace="thickmathspace"'];
        $movable = ['', ' movablelimits="true"', ' movablelimits="false"'];
        $out = [];
        foreach (['∑', '∏', '∫', 'x', 'Δ', '+', '='] as $char) {
            foreach ([false, true] as $colored) {
                foreach ($movable as $m) {
                    foreach ($spacings as $sp) {
                        $mo = '<mo' . $m . $sp . '>' . $char . '</mo>';
                        $base = $colored ? '<mstyle mathcolor="red">' . $mo . '</mstyle>' : $mo;
                        foreach ([
                            '<munder>' . $base . '<mi>i</mi></munder>',
                            '<msub>' . $base . '<mi>i</mi></msub>',
                            '<munderover>' . $base . '<mn>0</mn><mn>1</mn></munderover>',
                            '<msubsup>' . $base . '<mn>0</mn><mn>1</mn></msubsup>',
                            $base, // alone in the row
                        ] as $wrapped) {
                            $out[] = '<math><mi>a</mi>' . $wrapped . '<mi>b</mi></math>';
                        }
                    }
                }
            }
        }

        return $out;
    }

    /** Whether a limits control directly follows a class group, whose body may nest braces two levels deep. */
    public static function controlAfterClass(string $tex): bool
    {
        return preg_match('/\\\\math(?:rel|bin|punct|ord|open|close|inner)(\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\})\\\\(?:no|display)?limits/', $tex) === 1;
    }

    public function test_the_guard_sees_a_control_after_a_nested_class_group(): void
    {
        self::assertTrue(self::controlAfterClass('a \mathrel{\textcolor{red}{\sum}}\limits_i b'));
        self::assertTrue(self::controlAfterClass('\mathbin{{\textcolor{red}{\sum}}}\displaylimits'));
        self::assertTrue(self::controlAfterClass('\mathrel{\sum}\nolimits_a'));
        self::assertFalse(self::controlAfterClass('\mathrel{x}_a \sum\limits_i'));
        self::assertFalse(self::controlAfterClass('\mathop{\textcolor{red}{\sum}}\limits_i'));
    }

    /** @return ?string why the input fails, or null */
    private static function failure(string $mathml): ?string
    {
        $node = MathmlReader::read($mathml)->node;
        $tex = TexWriter::write($node);
        if (self::controlAfterClass($tex)) {
            return 'control after class: ' . $tex;
        }
        if (preg_match('/\\\\mkern[^\\\\]*\\\\(?:no|display)?limits/', $tex) === 1) {
            return 'control after kern: ' . $tex;
        }
        $back = Math::tex($tex);
        foreach ([false, true] as $display) {
            $a = Math::layout($node, 18.0, $display)->width;
            $b = Math::layout($back, 18.0, $display)->width;
            if (abs($a - $b) > self::TOLERANCE) {
                return sprintf('width %.2f vs %.2f (%s): %s', $a, $b, $display ? 'display' : 'inline', $tex);
            }
        }

        return null;
    }

    public function test_the_failing_set_is_pinned(): void
    {
        $bad = [];
        foreach (self::inputs() as $mathml) {
            $why = self::failure($mathml);
            if ($why !== null) {
                $bad[$mathml] = $why;
            }
        }
        ksort($bad);
        $dump = getenv('OPERATOR_SWEEP_DUMP');
        if (is_string($dump) && $dump !== '') {
            file_put_contents($dump, implode("\n", array_map(static fn (string $k, string $v): string => $k . "\n    " . $v, array_keys($bad), $bad)) . "\n");
        }
        $keys = array_keys($bad);
        self::assertSame(self::PIN, [count($keys), substr(sha1(implode("\0", $keys)), 0, 12)], 'the failing set moved');
    }
}
