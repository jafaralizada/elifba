<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomAnswerShape;
use InvalidArgumentException;

/**
 * What `RoomJudge` reads (docs/architecture.md §12 "Room games",
 * `room_rounds.solution`, hidden from every serialization of the round):
 * the shape, the canonical ids — the one right item of a `choice`, the
 * items of a `set`, the order a `sequence` reveal shows — and, for a
 * `sequence`, the readings it accepts (`accept`): what the picked items
 * say, in order, must equal one of them, so two tiles that say the same
 * are interchangeable. B6 adds the word an anagram spells (`word`).
 *
 * @phpstan-type RoomSolutionArray array{shape: string, ids: list<string>, accept?: list<list<string>>}
 */
final readonly class RoomSolutionData
{
    /**
     * @param  list<string>  $ids
     * @param  list<list<string>>  $accept  a `sequence`'s readings; empty for the other shapes
     */
    public function __construct(
        public RoomAnswerShape $shape,
        public array $ids,
        public array $accept = [],
    ) {
        if (($shape === RoomAnswerShape::Sequence) !== ($accept !== [])) {
            throw new InvalidArgumentException('A sequence and only a sequence accepts readings.');
        }

        if ($shape === RoomAnswerShape::Choice && count($ids) !== 1) {
            throw new InvalidArgumentException('A choice has one right item.');
        }
    }

    /**
     * @param  RoomSolutionArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomAnswerShape::from($data['shape']), $data['ids'], $data['accept'] ?? []);
    }

    /**
     * @return RoomSolutionArray
     */
    public function toArray(): array
    {
        return [
            'shape' => $this->shape->value,
            'ids' => $this->ids,
            ...($this->accept === [] ? [] : ['accept' => $this->accept]),
        ];
    }
}
