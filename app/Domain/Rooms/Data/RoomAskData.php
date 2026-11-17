<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;

/**
 * A room round's question (docs/architecture.md §12 "The state",
 * `RoomRound.ask`), stored in `room_rounds.payload.ask` and served from the
 * phase `answer`: the answer's shape, how many items to pick, the layout
 * and the items an answer is made of. Never the solution: for a
 * `sequence`, the items are never in the order to tap.
 *
 * @phpstan-import-type RoomItemArray from RoomItemData
 * @phpstan-import-type RoomGridArray from RoomStudyData
 *
 * @phpstan-type RoomAskArray array{shape: string, pick: int, layout: string, grid: RoomGridArray|null, items: list<RoomItemArray>}
 */
final readonly class RoomAskData
{
    /**
     * @param  int  $pick  how many items an answer holds: 1 for a choice, the row's length for a sequence
     * @param  RoomGridArray|null  $grid
     * @param  list<RoomItemData>  $items
     */
    public function __construct(
        public RoomAnswerShape $shape,
        public int $pick,
        public RoomLayout $layout,
        public ?array $grid,
        public array $items,
    ) {}

    /**
     * @param  RoomAskArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            RoomAnswerShape::from($data['shape']),
            $data['pick'],
            RoomLayout::from($data['layout']),
            $data['grid'],
            array_map(RoomItemData::fromArray(...), $data['items']),
        );
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (RoomItemData $item): string => $item->id, $this->items);
    }

    /**
     * @return RoomAskArray
     */
    public function toArray(): array
    {
        return [
            'shape' => $this->shape->value,
            'pick' => $this->pick,
            'layout' => $this->layout->value,
            'grid' => $this->grid,
            'items' => array_map(static fn (RoomItemData $item): array => $item->toArray(), $this->items),
        ];
    }
}
