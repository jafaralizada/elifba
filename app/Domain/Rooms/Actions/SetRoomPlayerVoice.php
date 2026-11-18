<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomPlayerVoiceData;
use App\Domain\Rooms\Enums\RoomVoiceStanding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Domain\Rooms\Support\RoomVoice;
use App\Domain\Rooms\Support\RoomVoiceCut;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The host's «Səsə burax», «Susdur» and «Danışmağa icazə ver» for one
 * player (docs/architecture.md §12 "Live voice", ADR 031 decisions 9, 10
 * and 12): `PUT /rooms/{room}/members/{member}/voice` `{standing}`. Under
 * the room row's lock it refuses a closed room and changes nothing on the
 * host's own row (`RoomPolicy::voice` refused it already) or a removed one.
 *
 * It lets the player in if the host had not, and sets or lifts the mute,
 * which stays until the host lifts it — through the voice going off and
 * on. A change raises the revision and signals the room. While the room's
 * voice is on, a mute set or lifted for a player who was already let in
 * is told to the voice server (`RoomVoiceCut::permit()`): the whole
 * permission set, with or without the microphone. Letting a muted player
 * speak gives the permission back and opens no microphone; letting in
 * only means the player may now ask for a ticket.
 */
final readonly class SetRoomPlayerVoice
{
    /**
     * @throws AuthorizationException when the room is closed
     */
    public function handle(Room $room, RoomPlayer $member, RoomPlayerVoiceData $data): void
    {
        DB::transaction(static function () use ($room, $member, $data): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen()) {
                throw new AuthorizationException;
            }

            $row = $locked->players()->whereKey($member->id)->first();

            if ($row === null || $row->removed_at !== null || $row->user_id === null || RoomVoice::isHost($locked, $row)) {
                return;
            }

            $wasAdmitted = $row->voice_admitted_at !== null;
            $muted = $data->standing === RoomVoiceStanding::Muted;

            if ($wasAdmitted && ($row->voice_muted_at !== null) === $muted) {
                return;
            }

            $row->voice_admitted_at ??= now();
            $row->voice_muted_at = $muted ? ($row->voice_muted_at ?? now()) : null;
            $row->save();

            RoomSignal::raise($locked);

            if ($wasAdmitted && $locked->voiceIsOn()) {
                RoomVoiceCut::permit($locked, $row, ! $muted);
            }
        });
    }
}
