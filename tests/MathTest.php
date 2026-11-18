<?php

declare(strict_types=1);

namespace Phactorial\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phactorial\Layout\Context;
use Phactorial\Layout\Glyph;
use Phactorial\Layout\Rule;
use Phactorial\Math;
use Phactorial\Mathml\SafeXml;
use Phactorial\Node\Fraction;
use Phactorial\Node\Identifier;
use Phactorial\Node\Number;
use Phactorial\Node\Operator;
use Phactorial\Node\Row;
use Phactorial\Node\Scripts;
use Phactorial\Render\OmmlWriter;
use Phactorial\Render\SvgRenderer;
use Phactorial\Styles;
use Phactorial\Tokenizer;

final class MathTest extends TestCase
{
    public function test_builders_take_the_documented_parameter_names(): void
    {
        self::assertInstanceOf(Fraction::class, Math::frac(numerator: 'a', denominator: 'b'));
        self::assertInstanceOf(Scripts::class, Math::sup(base: 'x', superscript: '2'));
        self::assertInstanceOf(Scripts::class, Math::sub(base: 'x', subscript: 'i'));
        self::assertInstanceOf(Scripts::class, Math::subSup(base: 'x', subscript: 'i', superscript: '2'));
        self::assertInstanceOf(Operator::class, Math::bigOp(operator: '∑'));
        self::assertInstanceOf(Operator::class, Math::big(delimiter: '(', step: 2));
        self::assertSame([], Math::notes(node: Math::row('x')));
    }

    public function test_xarrow_takes_under_before_over(): void
    {
        $n = Math::xarrow('→', 'u', 'o');
        self::assertInstanceOf(Identifier::class, $n->under);
        self::assertInstanceOf(Identifier::class, $n->over);
        self::assertSame('u', $n->under->name);
        self::assertSame('o', $n->over->name);
    }

    /**
     * @param callable $target
     * @param array<string, mixed> $args
     */
    #[DataProvider('oldNameCalls')]
    public function test_old_parameter_names_are_gone(callable $target, array $args): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Unknown named parameter/');
        $target(...$args);
    }

    /** @return iterable<string, array{callable, array<string, mixed>}> */
    public static function oldNameCalls(): iterable
    {
        $node = Math::row('x');
        yield 'frac num' => [Math::frac(...), ['num' => 'a', 'den' => 'b']];
        yield 'sup sup' => [Math::sup(...), ['base' => 'x', 'sup' => '2']];
        yield 'sub sub' => [Math::sub(...), ['base' => 'x', 'sub' => 'i']];
        yield 'subSup sub and sup' => [Math::subSup(...), ['base' => 'x', 'sub' => 'i', 'sup' => '2']];
        yield 'bigOp op' => [Math::bigOp(...), ['op' => '∑']];
        yield 'big size' => [Math::big(...), ['delimiter' => '(', 'size' => 2]];
        yield 'notes root' => [Math::notes(...), ['root' => $node]];
        yield 'Font gid cp' => [Math::stixTwoMath()->gid(...), ['cp' => 65]];
        yield 'Box each fn' => [Math::layout($node, 12)->each(...), ['fn' => static function (): void {}]];
        yield 'SafeXml decode s' => [SafeXml::decode(...), ['s' => 'x']];
        yield 'oMathPara jc' => [OmmlWriter::oMathPara(...), ['root' => $node, 'jc' => 'left']];
    }

    public function test_math_constants_of_stix_two_math(): void
    {
        $m = Math::stixTwoMath()->math();
        self::assertSame(1000, Math::stixTwoMath()->unitsPerEm);
        self::assertSame(258, $m->constant('axisHeight'));
        self::assertSame(68, $m->constant('fractionRuleThickness'));
        self::assertSame(70, $m->constant('scriptPercentScaleDown'));
        self::assertSame(55, $m->constant('scriptScriptPercentScaleDown'));
        self::assertSame(55, $m->constant('radicalDegreeBottomRaisePercent'));
    }

    public function test_radical_variants_and_assembly(): void
    {
        $f = Math::stixTwoMath();
        $con = $f->math()->vertical($f->gid(0x221A));
        self::assertNotNull($con);
        self::assertGreaterThanOrEqual(3, count($con->variants));
        self::assertNotEmpty($con->parts);
        // Advance measurements grow with each variant.
        $adv = array_column($con->variants, 1);
        $sorted = $adv;
        sort($sorted);
        self::assertSame($sorted, $adv);
    }

    public function test_cmap_and_italic_mapping(): void
    {
        $f = Math::stixTwoMath();
        self::assertGreaterThan(0, $f->gid(0x1D465)); // italic x
        self::assertSame(0x1D465, Styles::italic(ord('x')));
        self::assertSame(0x210E, Styles::italic(ord('h')));
        self::assertSame(0x1D434, Styles::italic(ord('A')));
        self::assertGreaterThan(0, $f->gid(0x210E));
    }

    public function test_tokenizer_classifications(): void
    {
        $t = Tokenizer::tokenize('3.14x + 2 = y');
        self::assertInstanceOf(Number::class, $t[0]);
        self::assertSame('3.14', $t[0]->text);
        self::assertInstanceOf(Identifier::class, $t[1]);
        self::assertInstanceOf(Operator::class, $t[2]);
        self::assertSame('+', $t[2]->char);
        self::assertCount(6, $t); // whitespace dropped

        $minus = Tokenizer::tokenize('a-b')[1];
        self::assertInstanceOf(Operator::class, $minus);
        self::assertSame('−', $minus->char);
    }

    public function test_prefix_minus_has_no_spacing(): void
    {
        $c = new Context(Math::stixTwoMath(), 10);
        $m = new Operator('−');
        $m2 = new Operator('−');
        $w1 = $m->layoutAs($c, 'prefix')->width;
        $w2 = $m2->layoutAs($c, 'infix')->width;
        self::assertEqualsWithDelta(2 * 4 / 18 * 10, $w2 - $w1, 1e-6);
    }

    public function test_fraction_width_is_wider_part_plus_padding(): void
    {
        $c = new Context(Math::stixTwoMath(), 20, 0, true);
        $frac = Math::frac('abc', 'd');
        $fc = $c->fractionChild();
        $numW = Math::row('abc')->layout($fc)->width;
        $denW = Math::row('d')->layout($fc)->width;
        $box = $frac->layout($c);
        self::assertEqualsWithDelta(max($numW, $denW) + 2 * Fraction::padding($c), $box->width, 1e-6);
        $rules = array_filter($box->items, fn ($i) => $i instanceof Rule);
        self::assertCount(1, $rules);
        self::assertEqualsWithDelta(max($numW, $denW), array_values($rules)[0]->w, 1e-6);
    }

    public function test_inline_fraction_is_smaller_than_display(): void
    {
        $d = Math::layout(Math::frac('a', 'b'), 12, true);
        $i = Math::layout(Math::frac('a', 'b'), 12, false);
        self::assertLessThan($d->height(), $i->height());
        $sizes = array_map(fn ($g) => $g->size, array_filter($i->items, fn ($x) => $x instanceof Glyph));
        self::assertEqualsWithDelta(8.4, max([0.0, ...$sizes]), 1e-6); // 70% script scale of STIX Two Math
    }

    public function test_superscript_is_raised_and_smaller(): void
    {
        $box = Math::layout(Math::sup('x', '2'), 20);
        $g = array_values(array_filter($box->items, fn ($i) => $i instanceof Glyph));
        self::assertCount(2, $g);
        self::assertSame(20.0, $g[0]->size);
        self::assertEqualsWithDelta(14.0, $g[1]->size, 1e-6);
        self::assertLessThan(0, $g[1]->y); // above the baseline (y down)
        self::assertGreaterThan($g[0]->x, $g[1]->x);
    }

    public function test_stretchy_fence_grows_and_uses_assembly(): void
    {
        $small = Math::layout(Math::fenced('(', ')', 'x'), 20);
        $tall = Math::layout(Math::fenced('(', ')', Math::frac('1', Math::frac('1', Math::frac('1', 'x')))), 20);
        self::assertGreaterThan($small->height(), $tall->height());
        $veryTall = Math::layout(Math::fenced('[', ']', Math::frac(Math::frac('1', 'x'), Math::frac('1', Math::frac('1', 'y')))), 20);
        $nullText = array_filter($veryTall->items, fn ($i) => $i instanceof Glyph && $i->text === null);
        self::assertGreaterThan(2, count($nullText), 'assembly pieces carry no text');
    }

    public function test_svg_output(): void
    {
        $box = Math::layout(Math::row('x =', Math::frac('a', 'b')), 12);
        $svg = (new SvgRenderer(Math::stixTwoMath()))->render($box, 1);
        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertNotFalse(simplexml_load_string($svg));
    }
}
