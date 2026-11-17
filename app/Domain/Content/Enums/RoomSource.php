<?php

namespace App\Domain\Content\Enums;

/**
 * What an `odd_one_out` level draws its options from (docs/architecture.md
 * §12 "Room games", `content/rooms/levels.json`'s `source`): numbers, of
 * which all but one share a property; or words, all but one from one group
 * of `content/rooms/categories.json`.
 */
enum RoomSource: string
{
    case Numbers = 'numbers';
    case Words = 'words';

    /** What kind of item a round of this source shows. */
    public function kind(): RoomItemKind
    {
        return match ($this) {
            self::Numbers => RoomItemKind::Number,
            self::Words => RoomItemKind::Word,
        };
    }
}
