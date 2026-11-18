<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomStatus;
use App\Domain\Rooms\Models\Room;

/**
 * The room in its state (docs/architecture.md §12 "The state"; zod
 * `RoomState.room`): its id, whether it is open, the lock, the code
 * while it is open, and from T2 the host's switch for taunts (`taunts`,
 * ADR 029 decision 12). Nothing else: a room has no name.
 *
 * @phpstan-type RoomSummaryArray array{id: string, status: string, locked: bool, code: string|null, taunts: bool}
 */
final readonly class RoomSummaryData
{
    public function __construct(
        public string $id,
        public RoomStatus $status,
        public bool $locked,
        public ?string $code,
        public bool $taunts = true,
    ) {}

    public static function of(Room $room): self
    {
        $status = $room->status();

        return new self(
            id: $room->id,
            status: $status,
            locked: $room->locked_at !== null,
            code: $status === RoomStatus::Open ? $room->code : null,
            taunts: $room->taunts,
        );
    }

    /**
     * @param  RoomSummaryArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], RoomStatus::from($data['status']), $data['locked'], $data['code'], $data['taunts']);
    }

    /**
     * @return RoomSummaryArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'locked' => $this->locked,
            'code' => $this->code,
            'taunts' => $this->taunts,
        ];
    }
}
