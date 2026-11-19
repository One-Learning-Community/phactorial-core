<?php

declare(strict_types=1);

namespace Phactorial\Tests;

use Phactorial\MathRender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A hostile color value never reaches any output, whichever reader it came through. */
final class ColorHostileTest extends TestCase
{
    /** @return iterable<string, array{MathRender, list<string>}> */
    public static function hostile(): iterable
    {
        yield 'tex switch' => [MathRender::fromTex('\color{red" onload="x} y'), ['onload', 'red"']];
        yield 'tex command' => [MathRender::fromTex('\textcolor{url(#a)}{x}'), ['url(', 'url?']];
        yield 'mathml declaration' => [MathRender::fromMathml('<math><mi mathcolor="red;fill:url(x)">x</mi></math>'), ['fill:url', 'url(']];
        yield 'mathml scheme' => [MathRender::fromMathml('<math><mi mathcolor="javascript:x">x</mi></math>'), ['javascript']];
        yield 'mathml markup' => [MathRender::fromMathml('<math><mstyle mathcolor="red&quot; onload=&quot;x"><mi>x</mi></mstyle></math>'), ['onload']];
    }

    /** @param list<string> $forbidden */
    #[DataProvider('hostile')]
    public function test_no_output_carries_the_value(MathRender $math, array $forbidden): void
    {
        $outputs = ['svg' => $math->svg(), 'omml' => $math->omml(), 'mathml' => $math->mathml(), 'tex' => $math->tex(), 'text' => $math->text()];
        foreach ($outputs as $kind => $out) {
            foreach ($forbidden as $text) {
                self::assertStringNotContainsString($text, $out, $kind);
            }
        }
        self::assertNotSame([], $math->notes());
    }
}
