<?php

namespace App\Domain\Rooms\Data;

use InvalidArgumentException;

/**
 * A row of a room's ranking (docs/architecture.md §12 "Scoring and what is
 * kept", ADR 022 decision 4; zod `RoomRankedPlayer`): the player's row id,
 * the points over closed rounds and the dense place — null for a player
 * with no points, who is listed after the places. The page labels a place
 * from its props (`place_labels`), never in TypeScript.
 *
 * @phpstan-type RoomRankedPlayerArray array{player_id: string, points: int, place: int|null}
 */
final readonly class RoomRankedPlayerData
{
    public function __construct(
        public string $player_id,
        public int $points,
        public ?int $place,
    ) {
        if ($points < 0 || ($points === 0) !== ($place === null) || ($place !== null && $place < 1)) {
            throw new InvalidArgumentException('A player with points has a place from 1; one without has none.');
        }
    }

    /**
     * @param  RoomRankedPlayerArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['player_id'], $data['points'], $data['place']);
    }

    /**
     * @return RoomRankedPlayerArray
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->player_id,
            'points' => $this->points,
            'place' => $this->place,
        ];
    }
}
