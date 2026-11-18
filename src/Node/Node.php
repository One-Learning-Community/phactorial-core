<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;

/**
 * A node of the formula tree.
 *
 * Sealed: the library's own types only. Extending or implementing it is unsupported, and a minor release may add a type.
 */
abstract class Node
{
    /**
     * Every child node, in reading order. A node without children returns an empty list.
     *
     * @return list<Node>
     */
    public function children(): array
    {
        return [];
    }

    /** @internal Engine detail, not public API. */
    abstract public function layout(Context $c): Box;
}
