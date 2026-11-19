<?php

declare(strict_types=1);

namespace Phactorial\Node;

use Phactorial\Layout\Box;
use Phactorial\Layout\Context;
use Phactorial\Layout\Run;
use Phactorial\OperatorDictionary;
use Phactorial\Styles;
use Phactorial\Utf8;

/** mi: a variable. A single letter is drawn italic (Mathematical Italic codepoints); longer names and symbols upright. */
final class Identifier extends Node
{
    /** True when drawn upright: function names, multi-letter names and ordinary symbols. */
    public readonly bool $upright;

    public readonly string $name;

    /**
     * @internal Engine detail, not public API. Whether the name's limits sit beside it inline (and stack in display style):
     * true for `\operatorname*`, false after `\limits`. Null is the default, which is true for the standard limit names
     * (lim, max, ...) and false for any other name. A value equal to the default is stored as null, so one reading has one tree.
     */
    public readonly ?bool $movableLimits;

    /**
     * Invalid UTF-8 in $name is replaced silently here; the Math builders also record a note.
     *
     * $function marks a function name (sin, log, \operatorname{foo}): TeX's Op class, which takes a thin space after it and
     * before it next to an ordinary. A multi-letter upright name that is not a function (`\mathrm{Fe}`, `kg`) is a plain ordinary.
     *
     * @internal Engine detail, not public API.
     */
    public function __construct(
        string $name,
        /** @internal Engine detail, not public API. */
        public readonly ?bool $italic = null,
        public readonly bool $function = false,
        ?bool $movableLimits = null,
    ) {
        $this->name = Utf8::clean($name);
        // Only a function name takes limits (an ordinary `\mathrm{max}` does not), and a value equal to the default is no value.
        $this->movableLimits = ! $function || $movableLimits === OperatorDictionary::isLimitName($this->name) ? null : $movableLimits;
        $this->upright = $italic === null ? mb_strlen($this->name) > 1 : ! $italic;
    }

    /** @internal Engine detail, not public API. */
    public function layout(Context $c): Box
    {
        $italic = ! $this->upright;
        $glyphs = [];
        foreach (mb_str_split($this->name) as $ch) {
            $glyphs[] = [$italic ? Styles::italic(mb_ord($ch)) : mb_ord($ch), $ch];
        }
        // Function names (sin, log) get a thin space after, so "sin x" does not run together. It is trailing space, as an
        // operator's is: a script sits before it.
        return Run::build($c, $glyphs, 0.0, $this->function && ! $italic && mb_strlen($this->name) > 1 ? $c->mu(3) : 0.0);
    }
}
