<?php

namespace App\Domain\Content\Enums;

/**
 * What an item of a room round is (docs/architecture.md §12 "The state",
 * `Item.kind`): a digit, a letter or a word shown as text, a number, a
 * shape shown as a picture, a cell of a grid. The six of the contract; a
 * level names those its mechanic shows (`recall_order`'s `kinds`), as far
 * as `RoomMechanic::kinds()` says there is material for them.
 */
enum RoomItemKind: string
{
    case Digit = 'digit';
    case Letter = 'letter';
    case Word = 'word';
    case Number = 'number';
    case Shape = 'shape';
    case Cell = 'cell';
}
