<?php

declare(strict_types=1);

namespace Phactorial;

/** How much a note's element cost: drawn some other way, or not drawn at all. */
enum NoteLevel
{
    case Approximated;
    case Dropped;
}
