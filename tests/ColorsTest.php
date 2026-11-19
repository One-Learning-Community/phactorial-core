<?php

declare(strict_types=1);

namespace Phactorial\Tests;

use Phactorial\Colors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorsTest extends TestCase
{
    public function test_is_hex_accepts_only_lowercase_rrggbb(): void
    {
        self::assertTrue(Colors::isHex('#0a1b2c'));
        foreach (['#0A1B2C', '#abc', 'red', '#0a1b2c ', "#0a1b2c\n", '0a1b2c', ''] as $bad) {
            self::assertFalse(Colors::isHex($bad), $bad);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function xcolorBaseNames(): iterable
    {
        $names = [
            'black' => '#000000', 'blue' => '#0000ff', 'brown' => '#bf8040', 'cyan' => '#00ffff',
            'darkgray' => '#404040', 'gray' => '#808080', 'green' => '#00ff00', 'lightgray' => '#bfbfbf',
            'lime' => '#bfff00', 'magenta' => '#ff00ff', 'olive' => '#808000', 'orange' => '#ff8000',
            'pink' => '#ffbfbf', 'purple' => '#bf0040', 'red' => '#ff0000', 'teal' => '#008080',
            'violet' => '#800080', 'white' => '#ffffff', 'yellow' => '#ffff00',
        ];
        foreach ($names as $name => $hex) {
            yield $name => [$name, $hex];
        }
    }

    #[DataProvider('xcolorBaseNames')]
    public function test_xcolor_base_names(string $name, string $hex): void
    {
        self::assertSame($hex, Colors::tex($name));
    }

    public function test_tex_hex(): void
    {
        self::assertSame('#ff0000', Colors::tex('#F00'));
        self::assertSame('#2350ff', Colors::tex('#2350FF'));
    }

    public function test_tex_models(): void
    {
        self::assertSame('#2350ff', Colors::tex('2350FF', 'HTML'));
        self::assertSame('#244fff', Colors::tex('0.14,0.31,1', 'rgb'));
        self::assertSame('#2350ff', Colors::tex('35,80,255', 'RGB'));
        self::assertSame('#808080', Colors::tex('0.5', 'gray'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function rejects(): iterable
    {
        yield 'mix' => ['red!50', null];
        yield 'negation' => ['-red', null];
        yield 'case' => ['Red', null];
        yield 'short hex' => ['#ff00', null];
        yield 'rgb over 1' => ['1.2,0,0', 'rgb'];
        yield 'RGB over 255' => ['256,0,0', 'RGB'];
        yield 'cmyk garbage' => ['x', 'cmyk'];
        yield 'empty' => ['', null];
        yield 'injection' => ['red" onload="x', null];
    }

    #[DataProvider('rejects')]
    public function test_tex_rejects(string $spec, ?string $model): void
    {
        self::assertNull(Colors::tex($spec, $model));
    }

    public function test_css(): void
    {
        self::assertSame('#ff0000', Colors::css('#F00'));
        self::assertSame('#aabbcc', Colors::css('#AABBCC'));
        self::assertSame('#008000', Colors::css('green'));
        self::assertSame('#808080', Colors::css('Gray'));
        self::assertSame('#c0c0c0', Colors::css('SILVER'));
        self::assertSame('#00ffff', Colors::css('aqua'));
        foreach (['rgb(1,2,3)', 'currentColor', 'transparent', 'orange', ''] as $bad) {
            self::assertNull(Colors::css($bad), $bad);
        }
    }

    public function test_css_has_the_sixteen_html4_names(): void
    {
        $names = [
            'black' => '#000000', 'silver' => '#c0c0c0', 'gray' => '#808080', 'white' => '#ffffff',
            'maroon' => '#800000', 'red' => '#ff0000', 'purple' => '#800080', 'fuchsia' => '#ff00ff',
            'green' => '#008000', 'lime' => '#00ff00', 'olive' => '#808000', 'yellow' => '#ffff00',
            'navy' => '#000080', 'blue' => '#0000ff', 'teal' => '#008080', 'aqua' => '#00ffff',
        ];
        foreach ($names as $name => $hex) {
            self::assertSame($hex, Colors::css($name), $name);
        }
        self::assertSame('#000080', Colors::css('NaVy'));
    }

    public function test_tex_models_allow_spaces_around_values(): void
    {
        self::assertSame('#244fff', Colors::tex('0.14, 0.31, 1', 'rgb'));
        self::assertSame('#2350ff', Colors::tex(' 35 ,80, 255', 'RGB'));
        self::assertSame('#808080', Colors::tex('0.5 ', 'gray'));
        self::assertNull(Colors::tex('0.1,,1', 'rgb'));
        self::assertNull(Colors::tex("0.1,\t0.2,1", 'rgb'));
    }

    public function test_unknown_note(): void
    {
        self::assertSame('unknown color `red!50`; drawn in the default color', Colors::unknown('red!50'));
    }

    public function test_unknown_recognizes_its_own_note_and_no_other(): void
    {
        self::assertTrue(Colors::isUnknown(Colors::unknown('red!50')));
        self::assertFalse(Colors::isUnknown('color on a paired delimiter is not drawn'));
        self::assertFalse(Colors::isUnknown('unknown colour'));
    }
}
