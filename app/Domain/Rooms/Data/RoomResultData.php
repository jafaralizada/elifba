<?php

namespace App\Domain\Rooms\Data;

/**
 * One player's result of a round in its reveal (docs/architecture.md §12
 * "The state", `reveal.results`): the player's row id, right or wrong and
 * the points. A player who did not answer the round has none. Served only
 * in the phase `reveal`.
 *
 * @phpstan-type RoomResultArray array{player_id: string, correct: bool, points: int}
 */
final readonly class RoomResultData
{
    public function __construct(
        public string $player_id,
        public bool $correct,
        public int $points,
    ) {}

    /**
     * @param  RoomResultArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['player_id'], $data['correct'], $data['points']);
    }

    /**
     * @return RoomResultArray
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->player_id,
            'correct' => $this->correct,
            'points' => $this->points,
        ];
    }
}
