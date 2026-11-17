<?php

namespace App\Domain\Rooms\Data;

/**
 * Who reads the state (docs/architecture.md §12 "The state"; zod
 * `RoomState.me`): the viewer's own player row, if any — a host who does
 * not play has none — and whether the viewer is the host. Never an account
 * id.
 *
 * @phpstan-type RoomViewerArray array{player_id: string|null, host: bool}
 */
final readonly class RoomViewerData
{
    public function __construct(
        public ?string $player_id,
        public bool $host,
    ) {}

    /**
     * @param  RoomViewerArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['player_id'], $data['host']);
    }

    /**
     * @return RoomViewerArray
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->player_id,
            'host' => $this->host,
        ];
    }
}
