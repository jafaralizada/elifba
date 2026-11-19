<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomVoiceSwitchData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Domain\Rooms\Support\RoomVoice;
use App\Domain\Rooms\Support\RoomVoiceCut;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The host's switch «Canlı səs» (docs/architecture.md §12 "Live voice",
 * ADR 031 decision 9): `PUT /rooms/{room}/voice` `{on}`, at any moment, a
 * round included. Under the room row's lock it refuses a closed room.
 *
 * A room's voice is on from its opening while the instance's voice is on
 * (`OpenRoom`), so this is mostly the host turning it off and on again
 * (ADR 031 decision 37, as amended 2026-11-19). On: sets `voice_at` and
 * lets in the host's row and every present player's that is not let in
 * yet — the list the host is looking at. Never refused: the limit on
 * voice rooms is the ticket's (`JoinRoomVoice`, decision 38). A mute stays
 * as the host left it. Off: clears `voice_at` and raises the epoch, which closes
 * the voice room for everyone. A change marks the room active, raises its
 * revision and signals it; then the voice server is told (`RoomVoiceCut`:
 * the voice room made, or the old one deleted). Asking for what already is
 * changes nothing.
 */
final readonly class SetRoomVoice
{
    /**
     * @throws AuthorizationException when the room is closed
     */
    public function handle(Room $room, RoomVoiceSwitchData $data): void
    {
        DB::transaction(static function () use ($room, $data): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen()) {
                throw new AuthorizationException;
            }

            if ($locked->voiceIsOn() === $data->on) {
                return;
            }

            if ($data->on) {
                self::turnOn($locked);
            } else {
                $before = $locked->voice_epoch;
                $locked->voice_at = null;
                $locked->voice_epoch = $before + 1;
            }

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            if ($data->on) {
                RoomVoiceCut::open($locked);
            } else {
                RoomVoiceCut::close($locked, $locked->voice_epoch - 1);
            }
        });
    }

    private static function turnOn(Room $locked): void
    {
        $locked->voice_at = now();

        foreach ($locked->players()->whereNull('voice_admitted_at')->whereNull('removed_at')->get() as $row) {
            if (RoomVoice::isHost($locked, $row) || $row->isPresent()) {
                $row->voice_admitted_at = now();
                $row->save();
            }
        }
    }
}
