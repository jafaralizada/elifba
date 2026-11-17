<?php

namespace App\Domain\Content\Enums;

use App\Domain\Language\Support\Alphabet;

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

    /**
     * How many different items of this kind a `recall_order` row is drawn
     * from — 10 digits, the alphabet's letters — so how long a row can be
     * with no item twice; null for a kind whose material is content that
     * whoever reads it counts (shapes: `content/rooms/shapes.json`, which
     * the validator counts in the root it checks) or is not there yet.
     */
    public function distinct(): ?int
    {
        return match ($this) {
            self::Digit => 10,
            self::Letter => count(Alphabet::letters()),
            default => null,
        };
    }
}
