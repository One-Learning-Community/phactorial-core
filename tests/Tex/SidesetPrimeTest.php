<?php

declare(strict_types=1);

namespace Phactorial\Tests\Tex;

use PHPUnit\Framework\TestCase;
use Phactorial\Math;
use Phactorial\Render\TexWriter;
use Phactorial\Tests\TreeAssert;

final class SidesetPrimeTest extends TestCase
{
    public function test_sideset_reads_the_prime_command_like_the_prime_character(): void
    {
        TreeAssert::assertSameTree(Math::tex("\\sideset{'}{}\\sum"), Math::tex('\sideset{\prime}{}\sum'), ignoreSource: true);
        self::assertStringContainsString('^\prime', TexWriter::write(Math::tex('\sideset{\prime}{}\sum')));
    }
}
