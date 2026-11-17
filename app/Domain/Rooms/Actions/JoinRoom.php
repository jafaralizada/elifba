<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings an account into a room with its code (docs/architecture.md §12
 * "Rooms and joining", ADR 020 decision 4). Under the room row's lock: a
 * closed room and an account the host removed are not found — the same
 * 404 as an unknown code; a present player stays as they are; a locked and
 * a full room refuse (on `code`, with nothing else about the room); a
 * player who left comes back to the same row; anyone else gets a new row.
 * A change raises the revision, marks the room active and signals it.
 */
final readonly class JoinRoom
{
    /**
     * @throws ModelNotFoundException<Room> when the room is closed or the account was removed from it
     * @throws ValidationException on `code` when the room is locked or full
     */
    public function handle(Room $room, User $account): RoomPlayer
    {
        return DB::transaction(static function () use ($room, $account): RoomPlayer {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen()) {
                throw (new ModelNotFoundException)->setModel(Room::class);
            }

            $row = $locked->players()->where('user_id', $account->id)->first();

            if ($row?->removed_at !== null) {
                throw (new ModelNotFoundException)->setModel(Room::class);
            }

            if ($row?->isPresent()) {
                return $row;
            }

            if ($locked->locked_at !== null) {
                throw ValidationException::withMessages(['code' => __('rooms.errors.locked')]);
            }

            if ($locked->players()->present()->count() >= Config::integer('elifba.rooms.max_players')) {
                throw ValidationException::withMessages(['code' => __('rooms.errors.full')]);
            }

            if ($row === null) {
                $row = new RoomPlayer;
                $row->room_id = $locked->id;
                $row->user_id = $account->id;
                $row->joined_at = now();
            }

            $row->left_at = null;
            $row->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $row;
        });
    }
}
