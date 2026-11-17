<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;

/**
 * A room round's question (docs/architecture.md §12 "The state",
 * `RoomRound.ask`), stored in `room_rounds.payload.ask` and served from the
 * phase `answer`: the answer's shape, how many items to pick, the layout,
 * what is shown with the question and cannot be picked (`shown`, B5: the
 * row a `next_in_sequence` round continues; empty for every other
 * mechanic) and the items an answer is made of (`items`, all that
 * `ids()` gives and `RecordRoomAnswer` accepts). Never the solution: for a
 * `sequence`, the items are never in the order to tap; for a `choice`, the
 * right one's place among them is drawn. A round stored before B5 has no
 * `shown` and reads as `[]`.
 *
 * @phpstan-import-type RoomItemArray from RoomItemData
 * @phpstan-import-type RoomGridArray from RoomStudyData
 *
 * @phpstan-type RoomAskArray array{shape: string, pick: int, layout: string, grid: RoomGridArray|null, shown?: list<RoomItemArray>, items: list<RoomItemArray>}
 */
final readonly class RoomAskData
{
    /**
     * @param  int  $pick  how many items an answer holds: 1 for a choice, the row's length for a sequence
     * @param  RoomGridArray|null  $grid
     * @param  list<RoomItemData>  $items  what an answer is made of
     * @param  list<RoomItemData>  $shown  shown with the question, never picked
     */
    public function __construct(
        public RoomAnswerShape $shape,
        public int $pick,
        public RoomLayout $layout,
        public ?array $grid,
        public array $items,
        public array $shown = [],
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
            array_map(RoomItemData::fromArray(...), $data['shown'] ?? []),
        );
    }

    /**
     * The ids an answer may pick: the items', never the shown ones'.
     *
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
            'shown' => array_map(static fn (RoomItemData $item): array => $item->toArray(), $this->shown),
            'items' => array_map(static fn (RoomItemData $item): array => $item->toArray(), $this->items),
        ];
    }
}
