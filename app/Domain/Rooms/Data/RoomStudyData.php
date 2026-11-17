<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomLayout;

/**
 * What a memory round shows to study (docs/architecture.md §12 "The
 * state", `RoomRound.study`), stored in `room_rounds.payload.study` and
 * served only in the phase `study`. Its items have ids of their own: no id
 * of the question (`RoomAskData`) is ever shown with the order to recall.
 *
 * @phpstan-import-type RoomItemArray from RoomItemData
 *
 * @phpstan-type RoomGridArray array{cols: int, rows: int}
 * @phpstan-type RoomStudyArray array{layout: string, grid: RoomGridArray|null, items: list<RoomItemArray>}
 */
final readonly class RoomStudyData
{
    /**
     * @param  RoomGridArray|null  $grid
     * @param  list<RoomItemData>  $items  in the order shown
     */
    public function __construct(
        public RoomLayout $layout,
        public ?array $grid,
        public array $items,
    ) {}

    /**
     * @param  RoomStudyArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomLayout::from($data['layout']), $data['grid'], array_map(RoomItemData::fromArray(...), $data['items']));
    }

    /**
     * @return RoomStudyArray
     */
    public function toArray(): array
    {
        return [
            'layout' => $this->layout->value,
            'grid' => $this->grid,
            'items' => array_map(static fn (RoomItemData $item): array => $item->toArray(), $this->items),
        ];
    }
}
