<?php

namespace App\Domain\Rooms\Enums;

/**
 * How a round lays its items out (docs/architecture.md §12 "The state"):
 * in a `row`, which the page may wrap, or on a `grid` of `{cols, rows}`
 * cells.
 */
enum RoomLayout: string
{
    case Row = 'row';
    case Grid = 'grid';
}
