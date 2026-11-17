<?php

namespace App\Domain\Rooms\Support;

use Carbon\CarbonImmutable;

/**
 * The only source of "now" in a room (docs/architecture.md §12 "The race",
 * ADR 021 decision 2): Unix milliseconds of the server's clock, which tests
 * move with `travelTo`. A device's clock is never read; a page only
 * estimates this one from the state's `server_ms`.
 */
final class RoomClock
{
    public static function nowMs(): int
    {
        return (int) CarbonImmutable::now()->getTimestampMs();
    }

    /**
     * A moment of the timetable as a date, for `travelTo` in a test or a
     * date shown on a page.
     */
    public static function at(int $ms): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs($ms);
    }
}
