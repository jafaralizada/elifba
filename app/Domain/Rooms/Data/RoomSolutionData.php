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
 * are interchangeable. A word game's also has its word (`word`, B6), as
 * the round writes it — an anagram's drawn word in the capitals of its
 * tiles, a spelling's right spelling — which the reveal shows; the judge
 * never reads it. A round stored without the key (B3–B5) reads null.
 *
 * @phpstan-type RoomSolutionArray array{shape: string, ids: list<string>, accept?: list<list<string>>, word?: string}
 */
final readonly class RoomSolutionData
{
    /**
     * @param  list<string>  $ids
     * @param  list<list<string>>  $accept  a `sequence`'s readings; empty for the other shapes
     * @param  string|null  $word  a word game's word, as the round writes it
     */
    public function __construct(
        public RoomAnswerShape $shape,
        public array $ids,
        public array $accept = [],
        public ?string $word = null,
    ) {
        if (($shape === RoomAnswerShape::Sequence) !== ($accept !== [])) {
            throw new InvalidArgumentException('A sequence and only a sequence accepts readings.');
        }

        if ($shape === RoomAnswerShape::Choice && count($ids) !== 1) {
            throw new InvalidArgumentException('A choice has one right item.');
        }

        if ($word === '') {
            throw new InvalidArgumentException('A word is never empty.');
        }
    }

    /**
     * @param  RoomSolutionArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomAnswerShape::from($data['shape']), $data['ids'], $data['accept'] ?? [], $data['word'] ?? null);
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
            ...($this->word === null ? [] : ['word' => $this->word]),
        ];
    }
}
