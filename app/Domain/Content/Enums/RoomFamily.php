<?php

namespace App\Domain\Content\Enums;

/**
 * The three families of adults' room games (docs/architecture.md §12 "Room
 * games", ADR 023): «Yaddaş», «Məntiq və ardıcıllıq», «Söz oyunları». The
 * enum holds all three from B3; a family is offered once it has a mechanic
 * `Rooms\Support\RoomPlayable` lists. Separate from the children's
 * `Mechanic` and `Unit`.
 */
enum RoomFamily: string
{
    /** Something is shown, then hidden: recall it. */
    case Memory = 'memory';

    /** Numbers and words that follow a rule, or one that does not. */
    case Logic = 'logic';

    /** Azerbaijani words: letters to order, spellings to tell apart. */
    case Words = 'words';

    /**
     * Whether a round of this family shows material to study before the
     * question (`room_rounds.study_ms` above 0): memory games only.
     */
    public function studies(): bool
    {
        return $this === self::Memory;
    }
}
