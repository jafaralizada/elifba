<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Models\RoomAnswer;

/**
 * The viewer's own answer in a round's reveal (docs/architecture.md §12
 * "The state", `reveal.mine`): the items picked in the order tapped, right
 * or wrong, the points and the time the server measured. Served only in
 * the phase `reveal`.
 *
 * @phpstan-type RoomMineArray array{picked: list<string>, correct: bool, points: int, elapsed_ms: int}
 */
final readonly class RoomMineData
{
    /**
     * @param  list<string>  $picked
     */
    public function __construct(
        public array $picked,
        public bool $correct,
        public int $points,
        public int $elapsed_ms,
    ) {}

    public static function of(RoomAnswer $answer): self
    {
        return new self($answer->picked, $answer->correct, $answer->points, $answer->elapsed_ms);
    }

    /**
     * @param  RoomMineArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['picked'], $data['correct'], $data['points'], $data['elapsed_ms']);
    }

    /**
     * @return RoomMineArray
     */
    public function toArray(): array
    {
        return [
            'picked' => $this->picked,
            'correct' => $this->correct,
            'points' => $this->points,
            'elapsed_ms' => $this->elapsed_ms,
        ];
    }
}
