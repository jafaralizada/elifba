<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomTauntStatus;

/**
 * The answer to a thrown taunt (docs/architecture.md §12 "Taunts (T2)",
 * ADR 029 decision 13; zod `RoomTauntReceipt`, fixture
 * tests/Fixtures/rooms/taunt-receipt.json): what was done with it and the
 * room's revision. The thrower's page reads its new balance and cooldown
 * from the state it asks for next.
 *
 * @phpstan-type RoomTauntReceiptArray array{status: string, revision: int}
 */
final readonly class RoomTauntReceiptData
{
    public function __construct(
        public RoomTauntStatus $status,
        public int $revision,
    ) {}

    /**
     * @param  RoomTauntReceiptArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomTauntStatus::from($data['status']), $data['revision']);
    }

    /**
     * @return RoomTauntReceiptArray
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'revision' => $this->revision,
        ];
    }
}
