<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomMechanic;

/**
 * The room's choice of the next game in its state (docs/architecture.md §12
 * "Games first", ADR 026 decision 11; zod `RoomSetup`): the game, its
 * difficulty and its number of rounds, as the host chose them and every
 * device shows them before the start — the host's, a present player's and
 * the big screen's alike — and `played`: the room's latest game is over and
 * was played with exactly this choice. A page shows that game's final while
 * `played` and the start screen otherwise; it compares nothing itself.
 * Nothing in it is a person's.
 *
 * @phpstan-type RoomSetupArray array{mechanic: string, difficulty: int, rounds: int, played: bool}
 */
final readonly class RoomSetupData
{
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public int $rounds,
        public bool $played,
    ) {}

    /**
     * @param  RoomSetupArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomMechanic::from($data['mechanic']), $data['difficulty'], $data['rounds'], $data['played']);
    }

    /**
     * @return RoomSetupArray
     */
    public function toArray(): array
    {
        return [
            'mechanic' => $this->mechanic->value,
            'difficulty' => $this->difficulty,
            'rounds' => $this->rounds,
            'played' => $this->played,
        ];
    }
}
