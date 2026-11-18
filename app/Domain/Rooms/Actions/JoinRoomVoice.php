<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\JoinRoomVoiceData;
use App\Domain\Rooms\Data\RoomVoiceTicketData;
use App\Domain\Rooms\Exceptions\RoomVoiceUnreachable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomVoice;
use App\Domain\Rooms\Support\RoomVoiceServer;
use App\Domain\Rooms\Support\RoomVoiceTicket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;

/**
 * A ticket to a room's voice room (docs/architecture.md §12 "Live voice",
 * ADR 031 decisions 14, 18–21): `POST /rooms/{room}/voice/tickets`, for the
 * host or a present player the host let in, of an open room whose voice
 * is on (`RoomPolicy::listen`). It makes sure the voice room exists at the
 * voice server (`CreateRoom` answers the one there) — no lock is held and
 * no transaction is open while it waits — then reads the room again: the
 * room may have closed, its voice gone off or its epoch moved meanwhile,
 * and a ticket is only ever for the voice room as it is now; a voice room
 * it made for a name that moved on is deleted again at once. Then it
 * stamps the account's agreement if this request gave it, and signs the
 * ticket (`RoomVoiceTicket`).
 *
 * A voice server that does not answer within `timeout_ms` throws
 * `RoomVoiceUnreachable` — 503 — and nothing is stamped. It writes nothing
 * of the room: no revision, no `seen_ms`, no signal.
 */
final readonly class JoinRoomVoice
{
    public function __construct(private RoomVoiceServer $server) {}

    /**
     * @throws AuthorizationException when the account may not hear the room's voice (any more)
     * @throws RoomVoiceUnreachable
     */
    public function handle(Room $room, User $account, JoinRoomVoiceData $data): RoomVoiceTicketData
    {
        [$room, $row] = self::listener($room, $account);

        // At most twice: once more when the epoch moved while the voice server answered.
        for ($try = 0; ; $try++) {
            $name = $room->voiceRoom();
            $this->server->createRoom($name);
            $now = Room::query()->find($room->id);

            // A removal, the voice off or a close came meanwhile, and its own
            // `DeleteRoom` may have come before this `CreateRoom`: the voice
            // room just made is for a fact that moved on, and goes again.
            if ($now === null || ! $now->isOpen() || ! $now->voiceIsOn() || $now->voiceRoom() !== $name) {
                $this->server->deleteRoom($name);
            }

            [$room, $row] = self::listener($room, $account);

            if ($room->voiceRoom() === $name || $try === 1) {
                break;
            }
        }

        if ($account->voice_agreed_at === null && $data->agree) {
            $account->voice_agreed_at = now();
            $account->save();
        }

        return new RoomVoiceTicketData(
            url: Config::string('elifba.rooms.voice.url'),
            token: RoomVoiceTicket::for($room, $row),
        );
    }

    /**
     * The room read again and the account's row, while the account may hear the room's voice.
     *
     * @return array{Room, RoomPlayer}
     *
     * @throws AuthorizationException
     */
    private static function listener(Room $room, User $account): array
    {
        $fresh = Room::query()->find($room->id);
        $row = $fresh?->rowOf($account);

        if ($fresh === null || $row === null || ! $fresh->isOpen() || ! $fresh->voiceIsOn() || ! RoomVoice::mayListen($fresh, $row)) {
            throw new AuthorizationException;
        }

        return [$fresh, $row];
    }
}
