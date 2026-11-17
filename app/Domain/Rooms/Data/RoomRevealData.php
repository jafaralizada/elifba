<?php

namespace App\Domain\Rooms\Data;

/**
 * A round's reveal (docs/architecture.md §12 "The state", `RoomRound.reveal`,
 * ADR 021 decision 6), served only in the phase `reveal`, once the round is
 * closed: the solution as its canonical ids — for a sequence, the
 * question's ids in the order to tap — and, for a word game, the word
 * (B6: the solution's `word`, as the round writes it; null otherwise);
 * the viewer's own answer; every player's result of the round, in the
 * order of the room's players. Before the reveal none of it leaves the
 * server.
 *
 * @phpstan-import-type RoomMineArray from RoomMineData
 * @phpstan-import-type RoomResultArray from RoomResultData
 *
 * @phpstan-type RoomRevealArray array{solution: list<string>, word: string|null, mine: RoomMineArray|null, results: list<RoomResultArray>}
 */
final readonly class RoomRevealData
{
    /**
     * @param  list<string>  $solution
     * @param  list<RoomResultData>  $results
     */
    public function __construct(
        public array $solution,
        public ?string $word,
        public ?RoomMineData $mine,
        public array $results,
    ) {}

    /**
     * @param  RoomRevealArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['solution'],
            $data['word'],
            $data['mine'] === null ? null : RoomMineData::fromArray($data['mine']),
            array_map(RoomResultData::fromArray(...), $data['results']),
        );
    }

    /**
     * @return RoomRevealArray
     */
    public function toArray(): array
    {
        return [
            'solution' => $this->solution,
            'word' => $this->word,
            'mine' => $this->mine?->toArray(),
            'results' => array_map(static fn (RoomResultData $result): array => $result->toArray(), $this->results),
        ];
    }
}
