<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Events\RoomChanged;
use App\Domain\Rooms\Exceptions\RoomChangeNotBroadcast;
use App\Domain\Rooms\Models\Room;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raises a room's revision and signals it (docs/architecture.md §12 "The
 * race", ADR 021 decisions 8–9). The action that changed what the room
 * sees calls it on the row it holds locked; `room.changed` goes out once
 * the transaction commits — never from inside it, and not at all when it
 * rolls back. A broadcast that fails is reported with ids only and never
 * fails the change.
 */
final class RoomSignal
{
    public static function raise(Room $locked): void
    {
        $locked->revision++;
        $locked->save();

        $roomId = $locked->id;
        $revision = $locked->revision;

        DB::afterCommit(static function () use ($roomId, $revision): void {
            try {
                app(Dispatcher::class)->dispatch(new RoomChanged($roomId, $revision));
            } catch (Throwable $failure) {
                report(new RoomChangeNotBroadcast($roomId, $revision, $failure::class));
            }
        });
    }
}
