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

    #[DataProvider('xcolorBaseNames')]
    public function test_a_base_name_mixed_at_100_is_the_name(string $name, string $hex): void
    {
        self::assertSame($hex, Colors::tex($name . '!100'));
        self::assertSame($hex, Colors::tex('--' . $name));
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

    /** @return iterable<string, array{string, string}> xcolor mixing expressions and the color LaTeX draws */
    public static function mixes(): iterable
    {
        yield 'red!50' => ['red!50', '#ff8080'];
        yield 'blue!20' => ['blue!20', '#ccccff'];
        yield 'red!12.5' => ['red!12.5', '#ffdfdf'];
        yield 'red!100' => ['red!100', '#ff0000'];
        yield 'red!0' => ['red!0', '#ffffff'];
        yield 'red!50!black' => ['red!50!black', '#800000'];
        yield 'red!30!blue' => ['red!30!blue', '#4d00b3'];
        yield 'red!30!blue!60!green' => ['red!30!blue!60!green', '#2e666b'];
        yield 'red!30!blue!60' => ['red!30!blue!60', '#9466d1'];
        yield 'orange!50!white' => ['orange!50!white', '#ffbf80'];
        yield 'violet!50!yellow' => ['violet!50!yellow', '#bf8040'];
        yield 'red!50!green' => ['red!50!green', '#808000'];
        yield '-red' => ['-red', '#00ffff'];
        yield '--red' => ['--red', '#ff0000'];
        yield '-red!50!blue' => ['-red!50!blue', '#80ff80'];
        yield 'cyan!50' => ['cyan!50', '#80ffff'];
        yield '-cyan' => ['-cyan', '#ff0000'];
        yield 'cyan!50!red' => ['cyan!50!red', '#808080'];
        yield 'red!50!cyan' => ['red!50!cyan', '#808080'];
        yield 'cyan!50!magenta' => ['cyan!50!magenta', '#8080ff'];
        yield 'olive!50' => ['olive!50', '#bfbf40'];
        yield 'olive!50!black' => ['olive!50!black', '#404000'];
        yield 'magenta!50!lime' => ['magenta!50!lime', '#df8080'];
        yield 'gray!50' => ['gray!50', '#bfbfbf'];
        yield '-gray' => ['-gray', '#808080'];
        yield 'black!30' => ['black!30', '#b3b3b3'];
        yield 'black!50!red' => ['black!50!red', '#800000'];
        yield 'gray!50!cyan' => ['gray!50!cyan', '#40bfbf'];
        yield 'a 0% step takes the name in its own model' => ['red!0!olive!50!blue', '#404040'];
        yield 'a 100% step keeps gray as gray' => ['gray!100!red!50!olive', '#808000'];
    }

    #[DataProvider('mixes')]
    public function test_xcolor_mixing(string $spec, string $hex): void
    {
        self::assertSame($hex, Colors::tex($spec));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function rejects(): iterable
    {
        yield 'near miss red!' => ['red!', null];
        yield 'near miss red!!50' => ['red!!50', null];
        yield 'near miss red!50!' => ['red!50!', null];
        yield 'near miss Red!50' => ['Red!50', null];
        yield 'near miss red !50' => ['red !50', null];
        yield 'near miss red! 50' => ['red! 50', null];
        yield 'near miss red!50!#fff' => ['red!50!#fff', null];
        yield 'near miss #f00!50' => ['#f00!50', null];
        yield 'near miss red!101' => ['red!101', null];
        yield 'near miss red!-5' => ['red!-5', null];
        yield 'near miss red!.' => ['red!.', null];
        yield 'near miss red!50!.' => ['red!50!.', null];
        yield 'near miss red!50!nope' => ['red!50!nope', null];
        yield 'near miss !50' => ['!50', null];
        yield 'near miss -' => ['-', null];
        yield 'near miss --' => ['--', null];
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
