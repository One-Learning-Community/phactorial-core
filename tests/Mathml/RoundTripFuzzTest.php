<?php

declare(strict_types=1);

namespace Phactorial\Tests\Mathml;

use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Node;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\OmmlWriter;
use Phactorial\Render\TextWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Tex\TexReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Seeded fuzz: MathML written by the library must read back to the same layout.
 *
 * Run on demand with `--group fuzz`. Each check pins the exact set of mismatching inputs as a count and a short hash of
 * the sorted inputs, so any change to the set fails, whether it grows or shrinks. When a change moves a set, run with
 * `ROUND_TRIP_DUMP=<dir>` at the parent commit and at this one, diff the files, and update the constant in the same
 * commit with the reason.
 */
final class RoundTripFuzzTest extends TestCase
{
    /** Widths further apart than this (in points) count as a mismatch. */
    private const float TOLERANCE = 0.05;

    /**
     * Each \ce{->} arrow reads back this much narrower than it was written (known, tracked separately), so the
     * tolerance widens by this much per arrow. \ce{<=>} reads back 8.41pt wider, so those inputs are skipped in the
     * width checks (they still count in the no-throw checks). Without this, \ce inputs were most of every set and hid
     * regressions elsewhere.
     */
    private const float CE_ARROW_SLACK = 0.49;

    // Pins are [count, first 12 hex chars of sha1 of the sorted inputs joined by NUL]. Some of the pinned differences
    // are by design (named colors written as hex, aligned read back as darray).
    // The well-formed pin moved from [753, '7fde65a190bb'] when table rows got TeX's array strut. A \ce{->} arrow reads back from
    // MathML with a smaller ascent, which used to change a pmatrix or cases row and so its outer delimiter; the strut makes both
    // sides the same height, and 15 inputs now agree within the arrow slack. All 66 moved inputs are pmatrix or cases; none was added.
    private const array WELL_FORMED_PIN = [738, '3f071a8540ea'];
    private const array FENCED_PIN = [63, '13719d3a8cd9'];
    private const array FENCED_TEX_PIN = [5909, 'e082f087a002'];
    private const array SOUP_PIN = [300, '64859f5ea133'];

    /** Well-formed structured inputs that the reader accepts without notes. */
    #[Group('fuzz')]
    public function test_well_formed_round_trip(): void
    {
        $atoms = ['a', 'b', 'x', '1', '=', '\sim', '\to', '\leq', '\in', '\mid', '+', '-', '\cdot', ',', ';', ':', '!', "'", '\%', '\not=', '\neq', '\mathrel{:}', '\mathord{=}', '\mathbin{=}', '\mathrel{+}', '\textcolor{red}{=}', '\color{blue}', '{=}', '\stackrel{?}{=}', '\overset{a}{\to}', '=^2', '\to_x', 'n!^2', "f'", '\,', '\;', '\quad', '\!', '\Longrightarrow', '\coloneqq', '\colon', '\iff', '\ce{->}', '\ce{<=>}', '\ce{A -> B}'];
        $wrap = [['', ''], ['(', ')'], ['\left(', '\right)'], ['\{', '\}'], ['\left[', '\right.'], ['\left.', '\right]'], ['\left|', '\right|'], ['|', '|'], ['\langle', '\rangle'], ['\left\{', '\middle| x \right\}'], ['\left(', '\middle| b \right)'], ['\Big(', '\Big)'], ['\sqrt{', '}'], ['x^{', '}'], ['\frac{', '}{2}'], ['\textcolor{green}{', '}']];
        $env = [['\begin{aligned} a &', '\end{aligned}'], ['\begin{aligned} a &', ' \\\\ & = c\end{aligned}'], ['\begin{cases} 1 &', '\end{cases}'], ['\begin{array}{cc} a &', '\end{array}'], ['\begin{pmatrix}', '\end{pmatrix}']];
        mt_srand(3);
        $seq = static function () use ($atoms): string {
            $s = '';
            for ($j = mt_rand(1, 4); $j > 0; $j--) {
                $s .= $atoms[mt_rand(0, count($atoms) - 1)] . ' ';
            }

            return $s;
        };
        $inputs = [];
        $seen = [];
        for ($k = 0; $k < 20000; $k++) {
            [$o, $c] = $wrap[mt_rand(0, count($wrap) - 1)];
            $s = $seq() . $o . ' ' . $seq() . ' ' . $c . ' ' . (mt_rand(0, 1) === 1 ? $seq() : '');
            if (mt_rand(0, 3) === 0) {
                [$eo, $ec] = $env[mt_rand(0, count($env) - 1)];
                $s = "$eo $s $ec";
            }
            $s = (string) preg_replace('/\s+/', ' ', trim($s));
            if (!isset($seen[$s])) {
                $seen[$s] = true;
                $inputs[] = $s;
            }
        }

        $bad = [];
        $threw = [];
        foreach ($inputs as $s) {
            try {
                $read = TexReader::read($s);
                if ($read->notes() !== []) {
                    continue;
                }
                $this->compare($s, $read->node, $bad);
            } catch (\Throwable $e) {
                $threw[] = $s . ' => ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
            }
        }

        self::assertSame([], array_slice($threw, 0, 10), 'inputs that threw');
        $this->assertPinned(self::WELL_FORMED_PIN, $bad, 'well-formed width mismatches', 'well-formed');
    }

    /** Fenced and aligned inputs, where the reader restores dropped spacing. */
    #[Group('fuzz')]
    public function test_fenced_round_trip(): void
    {
        $tok = ['a', '=', '\sim', '\to', ',', ';', '+', '\mathord{=}', '\mathrel{:}', '\,', '\textcolor{red}{=}', '\mid', '!'];
        $fen = [['\left(', '\right)'], ['\{', '\}'], ['\left|', '\right|'], ['\left[', '\right.'], ['\begin{aligned} a &', '\end{aligned}'], ['(', ')'], ['', '']];
        mt_srand(11);
        $inputs = [];
        $seen = [];
        for ($k = 0; $k < 20000; $k++) {
            [$o, $c] = $fen[mt_rand(0, count($fen) - 1)];
            $s = $o . ' ';
            for ($j = mt_rand(1, 4); $j > 0; $j--) {
                $s .= $tok[mt_rand(0, count($tok) - 1)] . ' ';
            }
            $s .= $c;
            if (!isset($seen[$s])) {
                $seen[$s] = true;
                $inputs[] = $s;
            }
        }

        $bad = [];
        $texBad = [];
        $threw = [];
        foreach ($inputs as $s) {
            try {
                $node = TexReader::read($s)->node;
                $rt = $this->compare($s, $node, $bad);
                if (isset($bad[$s])) {
                    continue;
                }
                // Counts TeX spelling differences, not layout: about two thirds of inputs differ by design (hex colors,
                // darray for aligned, \hspace for spacing, \mathrel{:} read back as a plain colon).
                if (TexWriter::write($node) !== TexWriter::write($rt)) {
                    $texBad[$s] = [TexWriter::write($node), TexWriter::write($rt)];
                }
            } catch (\Throwable $e) {
                $threw[] = $s . ' => ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
            }
        }

        self::assertSame([], array_slice($threw, 0, 10), 'inputs that threw');
        $failures = array_filter([
            $this->pinFailure(self::FENCED_PIN, $bad, 'fenced width mismatches', 'fenced'),
            $this->pinFailure(self::FENCED_TEX_PIN, $texBad, 'fenced TeX output differing after read-back', 'fenced-tex'),
        ]);
        self::assertSame([], array_values($failures), implode("\n", $failures));
    }

    /** Raw token soup, malformed input included: no writer or the reader may throw. */
    #[Group('fuzz')]
    public function test_token_soup_never_throws(): void
    {
        $tok = ['a', 'b', '=', '\sim', '\to', '!', '\%', '+', '-', ',', ';', '(', ')', '\{', '\}', '|', '\|', '\left(', '\right)', '\middle|', '\,', '\!', '\mathrel{:}', '\mathord{=}', '\mathbin{=}', '\mathpunct{=}', '\color{red}', '\textcolor{red}{=}', '{=}', '^2', '_x', '\not', '\stackrel{a}{=}', "'", '\circ', '&', '\\\\', '\quad', '\cdot', '\ce{->}'];
        mt_srand(7);
        $threw = [];
        $bad = [];
        for ($k = 0; $k < 20000; $k++) {
            $s = '';
            for ($j = mt_rand(1, 8); $j > 0; $j--) {
                $s .= $tok[mt_rand(0, count($tok) - 1)] . ' ';
            }
            if (mt_rand(0, 3) === 0) {
                $s = '\begin{aligned} ' . $s . ' \end{aligned}';
            }
            try {
                $node = TexReader::read($s)->node;
                TextWriter::write($node);
                TexWriter::write($node);
                OmmlWriter::oMath($node);
                $this->compare($s, $node, $bad);
            } catch (\Throwable $e) {
                $threw[] = $s . ' => ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
            }
        }

        self::assertSame([], array_slice($threw, 0, 10), count($threw) . ' inputs threw; the first');
        $this->assertPinned(self::SOUP_PIN, $bad, 'token soup width mismatches', 'soup');
    }

    /**
     * Writes the node as MathML, reads it back, records a width mismatch under the input, and returns the node read back.
     *
     * @param array<string, array{float, float}> $bad
     */
    private function compare(string $s, Node $node, array &$bad): Node
    {
        $rt = MathmlReader::read(MathmlWriter::write($node))->node;
        if (str_contains($s, '\ce{<=>}')) {
            return $rt;
        }
        $arrows = substr_count($s, '\ce{->}') + substr_count($s, '\ce{A -> B}');
        $w = Math::layout($node, 18.0, false)->width;
        $rw = Math::layout($rt, 18.0, false)->width;
        if (abs($w - $rw) > self::TOLERANCE + self::CE_ARROW_SLACK * $arrows) {
            $bad[$s] = [round($w, 2), round($rw, 2)];
        }

        return $rt;
    }

    /**
     * @param array{int, string}   $pin
     * @param array<string, mixed> $bad
     */
    private function assertPinned(array $pin, array $bad, string $what, string $name): void
    {
        $failure = $this->pinFailure($pin, $bad, $what, $name);
        self::assertSame('', $failure, $failure);
    }

    /**
     * Returns '' when the mismatch set matches the pin, or a message that says how it moved.
     *
     * @param array{int, string}   $pin
     * @param array<string, mixed> $bad
     */
    private function pinFailure(array $pin, array $bad, string $what, string $name): string
    {
        $keys = array_map('strval', array_keys($bad));
        sort($keys, SORT_STRING);
        $dump = getenv('ROUND_TRIP_DUMP');
        if (is_string($dump) && $dump !== '') {
            $lines = [];
            foreach ($keys as $key) {
                $lines[] = $key . "\t" . json_encode($bad[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            file_put_contents(rtrim($dump, '/') . '/' . $name . '.txt', implode("\n", $lines) . "\n");
        }
        $hash = substr(sha1(implode("\0", $keys)), 0, 12);
        if (count($keys) === $pin[0] && $hash === $pin[1]) {
            return '';
        }
        $plain = array_values(array_filter(
            $keys,
            static fn (string $k): bool => !preg_match('/\\ce|\\color|\\textcolor|aligned/', $k),
        ));

        return sprintf(
            "%s: the mismatch set moved. Pinned %d / %s, now %d / %s (delta %+d). First 10 current mismatches without "
            . "\\ce, \\color, \\textcolor or aligned: %s. Run with ROUND_TRIP_DUMP=<dir> at the parent commit and at "
            . "this one, and diff the files. If the move is intended, update the pin with the reason.",
            $what,
            $pin[0],
            $pin[1],
            count($keys),
            $hash,
            count($keys) - $pin[0],
            json_encode(array_slice($plain, 0, 10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
