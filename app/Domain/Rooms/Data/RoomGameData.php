<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Models\RoomGame;

/**
 * The room's latest game in its state (docs/architecture.md §12 "The
 * state"; zod `RoomGame`): what is played — the mechanic at a difficulty,
 * with how many rounds — and whether it is `live` or `over`. Never its
 * seed: the rounds it builds are the server's until each is served.
 *
 * @phpstan-type RoomGameArray array{id: string, mechanic: string, difficulty: int, rounds: int, phase: string}
 */
final readonly class RoomGameData
{
    public function __construct(
        public string $id,
        public RoomMechanic $mechanic,
        public int $difficulty,
        public int $rounds,
        public RoomGamePhase $phase,
    ) {}

    public static function of(RoomGame $game, RoomGamePhase $phase): self
    {
        return new self($game->id, $game->mechanic, $game->difficulty, $game->rounds->count(), $phase);
    }

    /**
     * @param  RoomGameArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], RoomMechanic::from($data['mechanic']), $data['difficulty'], $data['rounds'], RoomGamePhase::from($data['phase']));
    }

    /**
     * @return RoomGameArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mechanic' => $this->mechanic->value,
            'difficulty' => $this->difficulty,
            'rounds' => $this->rounds,
            'phase' => $this->phase->value,
        ];
    }
}
