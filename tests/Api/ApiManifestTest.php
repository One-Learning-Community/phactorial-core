<?php

declare(strict_types=1);

namespace Phactorial\Tests\Api;

use PHPUnit\Framework\TestCase;

final class ApiManifestTest extends TestCase
{
    public function test_the_public_api_matches_the_manifest(): void
    {
        $manifest = ApiManifest::build(__DIR__ . '/../../src', 'Phactorial');
        $file = __DIR__ . '/api.txt';

        if (getenv('UPDATE_API') === '1') {
            file_put_contents($file, $manifest);
            self::markTestSkipped('api.txt rewritten');
        }

        self::assertSame(
            (string) file_get_contents($file),
            $manifest,
            'The public API changed. Review the diff, then run UPDATE_API=1 vendor/bin/phpunit --filter ApiManifestTest.',
        );
    }
}
