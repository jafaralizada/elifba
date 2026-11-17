<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomAnswerStatus;

/**
 * The answer to a posted answer (docs/architecture.md §12 "The race", ADR
 * 021 decision 3; zod `RoomAnswerReceipt`, fixture
 * tests/Fixtures/rooms/receipt.json): what was done with it and the room's
 * revision — the same body for a right and a wrong answer, so a second
 * account in the room cannot scout with the first.
 *
 * @phpstan-type RoomAnswerReceiptArray array{status: string, revision: int}
 */
final readonly class RoomAnswerReceiptData
{
    public function __construct(
        public RoomAnswerStatus $status,
        public int $revision,
    ) {}

    /**
     * @param  RoomAnswerReceiptArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomAnswerStatus::from($data['status']), $data['revision']);
    }

    /**
     * @return RoomAnswerReceiptArray
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'revision' => $this->revision,
        ];
    }
}
