<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;

/** mspace: horizontal space of $em times the font size, no height. A negative width pulls the next item back. */
final class Space extends Node
{
    /**
     * Widths beyond this are clamped by the builder and the reader.
     *
     * @internal Engine detail, not public API.
     */
    public const float MAX_EM = 100.0;

    /** @internal Engine detail, not public API. */
    public function __construct(public readonly float $em)
    {
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        return new Box($this->em * $c->size());
    }
}
