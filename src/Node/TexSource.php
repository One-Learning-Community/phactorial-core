<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;

/**
 * Content with the TeX it was read from, for a construct the reader takes in and either does not draw (a box, a
 * strike-through, an equation number) or draws from another node (a color, whose spelling it keeps). It lives outside
 * the Fallback that notes the construct, because a Fallback is only made for the first note of its kind. Drawing and
 * every other writer see through it.
 *
 * With a $close string, $tex is the head and the TeX writer writes `$tex . content . $close`, so what is inside is
 * written the way the writer writes any content. With $close null, $tex is the whole source, written as it stands.
 */
final class TexSource extends Node
{
    /** @internal Engine detail, not public API. */
    public function __construct(
        public readonly Node $content,
        /** @internal Engine detail, not public API. */
        public readonly string $tex,
        /** @internal Engine detail, not public API. */
        public readonly ?string $close = null,
    ) {
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return [$this->content];
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        return $this->content->layout($c);
    }

    /**
     * Whether this is a color switch written without a brace of its own (`\color{red}` inside its group): the source of a
     * switch that colors the rest of the row it stands in, accepted or rejected.
     *
     * @internal Engine detail, not public API.
     */
    public function isColorSwitch(): bool
    {
        return $this->close === '' && str_starts_with($this->tex, '\\color');
    }

    /**
     * Whether this is a color switch that opens a group (`{\color{red} x}`): an atom of the row around it, as in TeX.
     *
     * @internal Engine detail, not public API.
     */
    public function isBracedColorSwitch(): bool
    {
        return $this->close === '}' && str_starts_with($this->tex, '{\\color');
    }
}
