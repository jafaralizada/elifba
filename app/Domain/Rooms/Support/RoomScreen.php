<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;

/**
 * Whether a room has its big screen (docs/architecture.md §12 "A room
 * without a screen", ADR 028 as amended 2026-11-19): the screen reads the
 * state at least every `beatMs()` while it is shown (`?for=screen`, which
 * `RecordScreenRead` keeps in `rooms.screen_seen_ms`), and a room whose
 * screen has not read within `goneMs()` — three beats — has none: the
 * screen was closed, hidden or never opened. Derived, never stored; the
 * state's `room.screen` says it, and a phone in a room without a screen
 * plays the room's cues too.
 */
final class RoomScreen
{
    /** How often a shown screen reads the state at least (`elifba.rooms.screen.beat_ms`). */
    public static function beatMs(): int
    {
        return (int) config('elifba.rooms.screen.beat_ms');
    }

    /** A screen silent for this long is gone: three beats, so one late read never drops it. */
    public static function goneMs(): int
    {
        return 3 * self::beatMs();
    }

    public static function present(Room $room, int $nowMs): bool
    {
        return $room->isOpen()
            && $room->screen_seen_ms !== null
            && $nowMs - $room->screen_seen_ms <= self::goneMs();
    }
}
