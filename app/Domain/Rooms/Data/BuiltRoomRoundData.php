<?php

namespace App\Domain\Rooms\Data;

use InvalidArgumentException;

/**
 * A room round as a builder makes it (docs/architecture.md §12 "Room
 * games"), before it is stored: what players may see (`payload()`: the
 * study material and the question), what only the judge reads
 * (`solution`) and its times.
 *
 * @phpstan-import-type RoomStudyArray from RoomStudyData
 * @phpstan-import-type RoomAskArray from RoomAskData
 *
 * @phpstan-type RoomPayloadArray array{study: RoomStudyArray|null, ask: RoomAskArray}
 */
final readonly class BuiltRoomRoundData
{
    /**
     * @param  int  $studyMs  0 unless the round has something to study
     */
    public function __construct(
        public ?RoomStudyData $study,
        public RoomAskData $ask,
        public RoomSolutionData $solution,
        public int $studyMs,
        public int $answerMs,
    ) {
        if (($study === null) !== ($studyMs === 0)) {
            throw new InvalidArgumentException('A round is studied for a time exactly when it has something to study.');
        }
    }

    /**
     * `room_rounds.payload`: everything a player may see of the round, in
     * its phase — never the solution.
     *
     * @return RoomPayloadArray
     */
    public function payload(): array
    {
        return ['study' => $this->study?->toArray(), 'ask' => $this->ask->toArray()];
    }
}
