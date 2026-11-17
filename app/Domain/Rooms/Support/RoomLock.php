<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Support\TeacherLock;
use App\Domain\Rooms\Models\Room;

/**
 * The two locks of a room's writes (docs/architecture.md §12 "Rooms and
 * joining"): the room's row, which every change to a room takes first —
 * a join, a leave, a removal, the lock, the close — so a count and a check
 * see every committed row; and the host's `users` row (the lock a
 * teacher's words take, `TeacherLock`), which opening a room takes so that
 * one host never has two open rooms. `FOR UPDATE` on PostgreSQL; on SQLite
 * the transaction itself serializes writers.
 */
final class RoomLock
{
    /**
     * The room's row, read again under the lock; null once it is gone.
     */
    public static function room(Room $room): ?Room
    {
        return Room::query()->whereKey($room->id)->lockForUpdate()->first();
    }

    public static function host(int $hostId): void
    {
        TeacherLock::acquire($hostId);
    }
}
