<?php

declare(strict_types=1);

namespace Phactorial\Tests\Render;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;
use Phactorial\Math;
use Phactorial\Node\Node;
use Phactorial\Render\MathmlWriter;
use Phactorial\Render\OmmlWriter;
use Phactorial\Render\TexWriter;
use Phactorial\Render\TextWriter;
use PHPUnit\Framework\TestCase;

final class WritersRejectUnknownNodesTest extends TestCase
{
    public function test_every_writer_rejects_an_unknown_node(): void
    {
        $node = new class extends Node {
            public function layout(Context $c): Box
            {
                throw new \LogicException('not laid out');
            }
        };
        $roots = [
            'bare' => $node,
            'in a row' => Math::row('a', $node),
            'in a fraction' => Math::frac($node, 'b'),
        ];
        $writers = [
            'MathmlWriter::write' => static fn (Node $r): string => MathmlWriter::write($r),
            'TexWriter::write' => static fn (Node $r): string => TexWriter::write($r),
            'TextWriter::write' => static fn (Node $r): string => TextWriter::write($r),
            'OmmlWriter::oMath' => static fn (Node $r): string => OmmlWriter::oMath($r),
        ];

        foreach ($roots as $where => $root) {
            foreach ($writers as $name => $write) {
                $label = $name . ' ' . $where;
                try {
                    $write($root);
                    self::fail($label . ' accepted an unknown node');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringStartsWith('Unknown node ', $e->getMessage(), $label);
                    self::assertStringContainsString($node::class, $e->getMessage(), $label);
                }
            }
        }
    }
}
