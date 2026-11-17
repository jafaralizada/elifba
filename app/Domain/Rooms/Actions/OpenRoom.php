<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomCode;
use App\Domain\Rooms\Support\RoomLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a room (docs/architecture.md §12 "Rooms and joining", ADR 020): a
 * `rooms` row with a code from `RoomCode`, and the host as its first player
 * unless the host does not play. One open room per host, checked under the
 * lock on the host's `users` row; an idle room no longer counts.
 */
final readonly class OpenRoom
{
    public function __construct(private RoomCode $codes) {}

    /**
     * @throws ValidationException on `room` when the host has an open room
     */
    public function handle(User $host, OpenRoomData $data): Room
    {
        return DB::transaction(function () use ($host, $data): Room {
            RoomLock::host($host->id);

            if (Room::query()->open()->where('user_id', $host->id)->exists()) {
                throw ValidationException::withMessages(['room' => __('rooms.errors.already_open')]);
            }

            $room = $this->codes->issue(static fn (string $code): Room => DB::transaction(static function () use ($host, $code): Room {
                $room = new Room;
                $room->user_id = $host->id;
                $room->code = $code;
                $room->last_activity_at = now();
                $room->save();

                return $room;
            }));

            if ($data->plays) {
                $player = new RoomPlayer;
                $player->room_id = $room->id;
                $player->user_id = $host->id;
                $player->joined_at = now();
                $player->save();
            }

            return $room;
        });
    }
}
