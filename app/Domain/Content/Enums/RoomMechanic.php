<?php

namespace App\Domain\Content\Enums;

/**
 * The rules of an adults' room round (docs/architecture.md §12 "Room
 * games", ADR 023). The rules are code in `Rooms` (a builder in
 * `Rooms\Support\Mechanics`, an answer shape for `RoomJudge`, a view);
 * the enum lives in Content, as `Mechanic` does, because
 * `content/rooms/levels.json` names mechanics and the validator checks
 * them. Separate from `Mechanic`: no value of one is a value of the other.
 *
 * The enum grows with the steps that build each mechanic's builder and
 * its validator rules — B3 `recall_order`; B4 `recall_cells`; B5
 * `next_in_sequence`, `odd_one_out`; B6 `anagram`, `spelling` — so every
 * case has a builder and a level of it is checked in full.
 */
enum RoomMechanic: string
{
    /**
     * «Ardıcıllığı yadda saxla»: a row of digits, letters or shapes is
     * shown, then hidden; tap them in the order shown (`sequence`).
     */
    case RecallOrder = 'recall_order';

    /**
     * «Xanaları yadda saxla»: cells of a grid light up, then go dark; tap
     * the cells that were lit (`set`).
     */
    case RecallCells = 'recall_cells';

    /**
     * «Sıranı davam etdir»: a row of numbers follows a rule; choose what
     * comes next among four (`choice`).
     */
    case NextInSequence = 'next_in_sequence';

    /**
     * «Artıq olanı tap»: one of the numbers or words does not belong with
     * the others; choose it (`choice`).
     */
    case OddOneOut = 'odd_one_out';

    public function family(): RoomFamily
    {
        return match ($this) {
            self::RecallOrder, self::RecallCells => RoomFamily::Memory,
            self::NextInSequence, self::OddOneOut => RoomFamily::Logic,
        };
    }

    /**
     * Whether a round shows material to study first (`study_ms` above 0).
     */
    public function studies(): bool
    {
        return $this->family()->studies();
    }

    /**
     * The kinds of item a level of this mechanic may name in `kinds`, as
     * far as their material exists: shapes from B4 (`content/rooms/
     * shapes.json`); words wait for B6's word list (`content/rooms/
     * words.json`). A `recall_cells` level names none: its items are the
     * cells of its grid; nor does a logic level: `next_in_sequence` shows
     * numbers, and `odd_one_out` what its `source` says.
     *
     * @return list<RoomItemKind>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::RecallOrder => [RoomItemKind::Digit, RoomItemKind::Letter, RoomItemKind::Shape],
            self::RecallCells, self::NextInSequence, self::OddOneOut => [],
        };
    }
}
