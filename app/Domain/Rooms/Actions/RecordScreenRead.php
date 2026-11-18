<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use Illuminate\Database\Eloquent\Builder;

/**
 * The room's big screen read the state while it was shown
 * (docs/architecture.md §12 "A room without a screen", ADR 028 as amended
 * 2026-11-19): the only writer of `rooms.screen_seen_ms`, from which the
 * state's `room.screen` is derived (`RoomScreen::present()`). Called by
 * opening `rooms/screen` and by `GET /rooms/{room}/state?for=screen`,
 * which only the host of an open room may (`RoomPolicy::screen`); a hidden
 * screen asks nothing.
 *
 * At most once a second: one conditional update of the room's row, without
 * its lock. It neither raises the revision nor signals — a phone learns of
 * the screen at its next read, and every moment of a game is one — and it
 * never touches `last_activity_at`, so a screen left on keeps no idle room
 * open, nor `updated_at`.
 */
final readonly class RecordScreenRead
{
    /** Two reads closer than this write once. */
    private const int EVERY_MS = 1_000;

    public function handle(Room $room): void
    {
        if (! $room->isOpen()) {
            return;
        }

        $nowMs = RoomClock::nowMs();

        Room::query()
            ->whereKey($room->id)
            ->where(static fn (Builder $query) => $query->whereNull('screen_seen_ms')->orWhere('screen_seen_ms', '<=', $nowMs - self::EVERY_MS))
            ->toBase()
            ->update(['screen_seen_ms' => $nowMs]);

        if ($room->screen_seen_ms === null || $room->screen_seen_ms <= $nowMs - self::EVERY_MS) {
            $room->screen_seen_ms = $nowMs;
            $room->syncOriginalAttribute('screen_seen_ms');
        }
    }
}
