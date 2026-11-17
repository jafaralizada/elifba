<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomPhase;

/**
 * Where a room game stands at a moment (`RoomTimetable::at()`): the round's
 * 0-based position and its phase, or `over` without a round.
 */
final readonly class RoomMomentData
{
    public function __construct(
        public RoomPhase $phase,
        public ?int $round,
    ) {}

    public static function over(): self
    {
        return new self(RoomPhase::Over, null);
    }

    public function isOver(): bool
    {
        return $this->phase === RoomPhase::Over;
    }

    /**
     * Whether `$round` is open for answers now.
     */
    public function answers(int $round): bool
    {
        return $this->phase === RoomPhase::Answer && $this->round === $round;
    }
}
