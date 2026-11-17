<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomItemKind;

/**
 * An item of a room round (docs/architecture.md §12 "The state", `Item`):
 * an opaque id of 8 characters from the game's seeded source — it carries
 * no position, so neither its value nor the order of the ids tells what
 * the round shows or asks — and what it is: a text (a digit, a letter
 * cased by `Az::upper`, a word), a picture (`asset`) or a cell of a grid.
 *
 * @phpstan-type RoomItemArray array{id: string, kind: string, text: string|null, asset: string|null, cell: array{int, int}|null}
 */
final readonly class RoomItemData
{
    /**
     * @param  array{int, int}|null  $cell  `[col, row]`, for a cell
     */
    public function __construct(
        public string $id,
        public RoomItemKind $kind,
        public ?string $text = null,
        public ?string $asset = null,
        public ?array $cell = null,
    ) {}

    /**
     * @param  RoomItemArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], RoomItemKind::from($data['kind']), $data['text'], $data['asset'], $data['cell']);
    }

    /**
     * What the item says, as a `sequence` solution's readings compare it:
     * its text, else its picture, else its id. Two items that say the same
     * are interchangeable (§12 "Room games").
     */
    public function says(): string
    {
        return $this->text ?? $this->asset ?? $this->id;
    }

    /**
     * @return RoomItemArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'text' => $this->text,
            'asset' => $this->asset,
            'cell' => $this->cell,
        ];
    }
}
