<?php

namespace App\Domain\Rooms\Enums;

/**
 * Where a room game stands at a moment (docs/architecture.md §12 "The
 * race", ADR 021 decisions 5–6): a round's `countdown` (before round 0
 * opens), `study` (memory games only), `answer`, `reveal`; or `over`.
 * Derived by `RoomTimetable` at each request, never stored.
 */
enum RoomPhase: string
{
    case Countdown = 'countdown';
    case Study = 'study';
    case Answer = 'answer';
    case Reveal = 'reveal';
    case Over = 'over';
}
