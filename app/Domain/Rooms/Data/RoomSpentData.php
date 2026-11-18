<?php

namespace App\Domain\Rooms\Data;

/**
 * The taunts of one player counted at a round's close (docs/architecture.md
 * §12 "Taunts (T2)", `reveal.spent`, ADR 029 decisions 2 and 6): the
 * player's row id and how many, each costing `RoomPointsRule::TAUNT_COST`.
 * Served only in the reveal, beside the round's results, for a player with
 * a cost at this close; a page counts a total up from `points −
 * result.points + spent`. Nobody's cost reaches another phone before the
 * close.
 *
 * @phpstan-type RoomSpentArray array{player_id: string, count: int}
 */
final readonly class RoomSpentData
{
    public function __construct(
        public string $player_id,
        public int $count,
    ) {}

    /**
     * @param  RoomSpentArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['player_id'], $data['count']);
    }

    /**
     * @return RoomSpentArray
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->player_id,
            'count' => $this->count,
        ];
    }
}
