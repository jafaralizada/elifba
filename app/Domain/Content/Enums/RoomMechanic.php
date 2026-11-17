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
     * «Ardıcıllığı yadda saxla»: a row of digits or letters is shown, then
     * hidden; tap them in the order shown (`sequence`).
     */
    case RecallOrder = 'recall_order';

    public function family(): RoomFamily
    {
        return match ($this) {
            self::RecallOrder => RoomFamily::Memory,
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
     * far as their material exists: words wait for B6's word list
     * (`content/rooms/words.json`), shapes for B4's (`shapes.json`).
     *
     * @return list<RoomItemKind>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::RecallOrder => [RoomItemKind::Digit, RoomItemKind::Letter],
        };
    }
}
