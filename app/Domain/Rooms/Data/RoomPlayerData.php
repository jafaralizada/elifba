<?php

namespace App\Domain\Rooms\Data;

/**
 * A player in a room's state (docs/architecture.md §12 "The state",
 * "Adults' privacy"; zod `RoomPlayer`): the row's id — the only id of a
 * player a device gets — the account's name as it is now, null for a row
 * without an account («Oyunçu»), whether the player is in the room now and
 * whether it is the host. Never an account id or an address.
 *
 * @phpstan-type RoomPlayerArray array{id: string, name: string|null, present: bool, host: bool}
 */
final readonly class RoomPlayerData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $present,
        public bool $host,
    ) {}

    /**
     * @param  RoomPlayerArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['present'], $data['host']);
    }

    /**
     * @return RoomPlayerArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'present' => $this->present,
            'host' => $this->host,
        ];
    }
}
