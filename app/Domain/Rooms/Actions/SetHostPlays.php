<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\HostPlaysData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The host's «Mən də oynayıram» on the start screen (docs/architecture.md
 * §12 "Games first", ADR 026 decision 12): `PUT /rooms/{room}/plays`. Under
 * the room row's lock it refuses a closed room and a room whose game is
 * running (`RoomPolicy::start` refused them already); `false` sets the
 * host's own row to left, as `LeaveRoom` does — no round is open to close —
 * and `true` brings it back, or makes the row of a room the host opened
 * without one. The lock of «Kilidlə» never keeps the host out; a full room
 * does. A change marks the room active, raises its revision and signals
 * it; asking for what already is changes nothing.
 */
final readonly class SetHostPlays
{
    /**
     * @throws AuthorizationException when the room is closed or a game is running in it
     * @throws ValidationException on `plays` when the room is full
     */
    public function handle(Room $room, HostPlaysData $data): void
    {
        DB::transaction(static function () use ($room, $data): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen() || $locked->runningGame(RoomClock::nowMs()) !== null) {
                throw new AuthorizationException;
            }

            $row = $locked->players()->where('user_id', $locked->user_id)->first();

            if (($row?->isPresent() ?? false) === $data->plays) {
                return;
            }

            if ($data->plays) {
                if ($locked->players()->present()->count() >= Config::integer('elifba.rooms.max_players')) {
                    throw ValidationException::withMessages(['plays' => __('rooms.errors.full')]);
                }

                if ($row === null) {
                    $row = new RoomPlayer;
                    $row->room_id = $locked->id;
                    $row->user_id = $locked->user_id;
                    $row->joined_at = now();
                }

                $row->left_at = null;
            } else {
                /** @var RoomPlayer $row a present row */
                $row->left_at = now();
            }

            $row->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
