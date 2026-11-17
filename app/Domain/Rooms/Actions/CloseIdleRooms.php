<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomLock;
use Illuminate\Support\Facades\DB;

/**
 * Closes the rooms with no join, game or answer for
 * `elifba.rooms.idle_hours` (docs/architecture.md §1 "Scheduled", §12
 * "Rooms and joining"). They already count as closed (`Room::isOpen()`);
 * this writes it and clears their codes. Each is checked again under its
 * row lock, so a room someone joined a moment ago stays open. Scheduled
 * hourly (`rooms:close-idle` in routes/console.php).
 */
final readonly class CloseIdleRooms
{
    /**
     * @return int how many rooms were closed
     */
    public function handle(): int
    {
        $closed = 0;

        $ids = Room::query()
            ->whereNull('closed_at')
            ->where('last_activity_at', '<=', Room::idleSince())
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $closed += DB::transaction(static function () use ($id): int {
                $room = Room::query()->whereKey((string) $id)->first();
                $locked = $room === null ? null : RoomLock::room($room);

                if ($locked === null || $locked->closed_at !== null || $locked->isOpen()) {
                    return 0;
                }

                CloseRoom::close($locked);

                return 1;
            });
        }

        return $closed;
    }
}
