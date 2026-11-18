<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomVoiceStanding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Config;

/**
 * A ticket to a room's voice room (docs/architecture.md §12 "Live voice",
 * ADR 031 decisions 6 and 20): a JWT, HS256 with the instance's voice
 * secret, through `firebase/php-jwt` — no LiveKit SDK. `iss` is the key,
 * `sub` the player's `room_players.id` and nothing else; it lets its
 * holder join for `ticket_seconds`.
 *
 * Every permission is written out, because the voice server grants what a
 * token leaves out: join this voice room, hear it, publish the microphone
 * only while the standing is `open`, never send data or change one's own
 * metadata. It never holds a name, metadata or attributes, nor
 * `roomCreate` (which would make a room the app did not), `roomAdmin`,
 * `roomList`, `roomRecord`, `hidden`, `recorder`, `agent` or
 * `ingressAdmin`. Only `JoinRoomVoice` asks for one, for a row `RoomPolicy::listen`
 * let through.
 */
final class RoomVoiceTicket
{
    public static function for(Room $room, RoomPlayer $row): string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return JWT::encode([
            'iss' => Config::string('elifba.rooms.voice.key'),
            'sub' => $row->id,
            'nbf' => $now,
            'exp' => $now + Config::integer('elifba.rooms.voice.ticket_seconds'),
            'video' => [
                'room' => $room->voiceRoom(),
                'roomJoin' => true,
                'canSubscribe' => true,
                'canPublish' => RoomVoice::standingOf($room, $row) === RoomVoiceStanding::Open,
                'canPublishData' => false,
                'canPublishSources' => ['microphone'],
                'canUpdateOwnMetadata' => false,
            ],
        ], Config::string('elifba.rooms.voice.secret'), 'HS256');
    }
}
