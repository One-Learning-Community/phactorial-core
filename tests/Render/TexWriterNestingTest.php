<?php

declare(strict_types=1);

namespace Phactorial\Tests\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Mathml\MathmlReader;
use Phactorial\Node\Node;
use Phactorial\Render\TexWriter;

/**
 * Writing a tree is linear in its size: no node is written more than a constant number of times, however deep it sits.
 */
final class TexWriterNestingTest extends TestCase
{
    private const int DEPTH = 25;

    /** Seconds. Linear writing takes a few milliseconds; a doubling per level takes minutes at this depth. */
    private const float BOUND = 0.5;

    /**
     * @return iterable<string, array{Node}>
     */
    public static function shapes(): iterable
    {
        $wrap = static function (string $open, string $close): string {
            return str_repeat($open, self::DEPTH) . 'x' . str_repeat($close, self::DEPTH);
        };
        yield 'braced scripts' => [Math::tex($wrap('{', '}_a'))];
        yield 'underset' => [Math::tex($wrap('\underset{a}{', '}'))];
        yield 'fence with script' => [Math::tex($wrap('\left( ', ' \right)^2'))];
        yield 'underset with a script' => [Math::tex($wrap('\underset{a}{', '}_b'))];
        yield 'overset with a script' => [Math::tex($wrap('\overset{a}{', '}^b'))];
        yield 'sum in a subscript' => [Math::tex($wrap('\sum_{', '}'))];
        yield 'operator name in a subscript' => [Math::tex($wrap('\operatorname*{ab}_{', '}'))];
        yield 'colored scripts' => [Math::tex($wrap('\textcolor{red}{', '}_a'))];
        $xml = '<mi>x</mi>';
        for ($i = 0; $i < self::DEPTH; $i++) {
            $xml = '<msub>' . $xml . '<mi>a</mi></msub>';
        }
        yield 'msub from MathML' => [MathmlReader::read('<math>' . $xml . '</math>')->node];
        $xml = '<mi>x</mi>';
        for ($i = 0; $i < self::DEPTH; $i++) {
            $xml = '<munder>' . $xml . '<mi>a</mi></munder>';
        }
        yield 'munder from MathML' => [MathmlReader::read('<math>' . $xml . '</math>')->node];
    }

    #[DataProvider('shapes')]
    public function test_nested_writing_is_linear(Node $node): void
    {
        $start = microtime(true);
        $tex = TexWriter::write($node);
        $elapsed = microtime(true) - $start;
        self::assertNotSame('', $tex);
        self::assertLessThan(self::BOUND, $elapsed);
    }
}
