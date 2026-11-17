<?php

namespace App\Domain\Content\Enums;

/**
 * The rules a `next_in_sequence` row may follow (docs/architecture.md §12
 * "Room games", ADR 023 decision 7): the rules are code — their arithmetic
 * is `Rooms\Support\Mechanics\SequenceRules` — and which of them a
 * difficulty uses is data (`content/rooms/levels.json`'s `rules`). The
 * names live in Content, as `RoomMechanic` does, because the level file
 * names them and the validator checks them.
 */
enum RoomSequenceRule: string
{
    /** The same step each time: 4, 7, 10, 13 → 16 (up or down). */
    case Arithmetic = 'arithmetic';

    /** The same factor each time: 3, 6, 12, 24 → 48 (up or down). */
    case Geometric = 'geometric';

    /** Two steps by turns: 2, 7, 5, 10, 8 → 13 (+5, −2). */
    case Alternating = 'alternating';

    /** Consecutive squares: 9, 16, 25, 36 → 49. */
    case Squares = 'squares';

    /** Each the sum of the two before it: 2, 3, 5, 8 → 13. */
    case Fibonacci = 'fibonacci';

    /** Two rows, each with a step of its own, by turns: 1, 20, 3, 17, 5, 14 → 7. */
    case Interleaved = 'interleaved';

    /**
     * How many terms a row of this rule shows at least, so that the row
     * tells the rule from the other five: with fewer, another rule fits
     * the same terms and continues them otherwise — four terms of
     * squares, for one, are also two rows of a step each (1, 4, 9, 16 →
     * 17 as well as 25). The builder still checks every row against all
     * six and builds again whatever is arguable; this is the least that
     * leaves it something to build. An arithmetic row needs three equal
     * steps; the rest need a fifth term, two interleaved rows three
     * terms each.
     */
    public function minTerms(): int
    {
        return match ($this) {
            self::Arithmetic => 4,
            self::Geometric, self::Alternating, self::Squares, self::Fibonacci => 5,
            self::Interleaved => 6,
        };
    }
}
