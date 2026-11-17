<?php

namespace App\Domain\Rooms\Support\Mechanics;

use Random\Randomizer;

/**
 * Item ids for one round (docs/architecture.md §12 "The state", `Item.id`):
 * 8 characters of a–z and 0–9 from the game's seeded source, none twice in
 * the round. They carry no position — unlike a children's round, whose ids
 * follow the board's order — so neither an id nor the order of the ids
 * tells what a round shows or asks.
 */
final class RoomItemIds
{
    public const int LENGTH = 8;

    private const string CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @var array<string, true>
     */
    private array $used = [];

    public function __construct(
        private readonly Randomizer $random,
    ) {}

    public function next(): string
    {
        do {
            $id = $this->random->getBytesFromString(self::CHARACTERS, self::LENGTH);
        } while (isset($this->used[$id]));

        $this->used[$id] = true;

        return $id;
    }
}
