<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Exceptions\RoomVoiceUnreachable;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one class that talks to the voice server (docs/architecture.md §12
 * "Live voice", ADR 031 decision 22): Laravel's HTTP client against
 * `elifba.rooms.voice.api_url`, LiveKit's Twirp JSON calls of
 * `livekit.RoomService` — `CreateRoom`, `DeleteRoom`, `RemoveParticipant`,
 * `UpdateParticipant`, `ListRooms`, `ListParticipants` — each with a token
 * minted for that call alone (30 seconds; `roomCreate`, `roomAdmin` with
 * its room, or `roomList`), connecting and answering within `timeout_ms`.
 * No SDK, no queue, no webhook.
 *
 * A "not found" where the thing asked for is already gone — a room
 * deleted, a participant who left — is no error. Anything else that does
 * not answer 2xx in time throws `RoomVoiceUnreachable`, with the call and
 * the cause's class or status only. Nothing here logs.
 *
 * The voice server's answers are LiveKit's protojson with field names as
 * in the proto (`snake_case`), 64-bit numbers as strings, and every field
 * present; an error is `{code, msg}` (`not_found`, `unauthenticated`,
 * `malformed`…). A request body is always an object: `[]` is "malformed".
 */
final class RoomVoiceServer
{
    /** How long the app's own tokens live, seconds. */
    private const int CALL_TOKEN_SECONDS = 30;

    /**
     * Whether a voice server is named: its key, its secret and the address
     * the app calls. The switch is not asked: the sweep runs with it off
     * too, to close what was left open (§12 "Live voice").
     */
    public static function configured(): bool
    {
        foreach (['key', 'secret', 'api_url'] as $key) {
            $value = Config::get("elifba.rooms.voice.{$key}");

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Makes the voice room, or finds it made (`CreateRoom` answers the one
     * there). Nobody joins it: it closes itself after five minutes
     * (`empty_timeout`); everyone left it: after 20 seconds
     * (`departure_timeout`) — the next ticket makes it again. At most the
     * room's players and a host who does not play.
     */
    public function createRoom(string $name): void
    {
        $this->call('CreateRoom', [
            'name' => $name,
            'empty_timeout' => 300,
            'departure_timeout' => 20,
            'max_participants' => Config::integer('elifba.rooms.max_players') + 1,
        ], ['roomCreate' => true], $name);
    }

    /** Closes the voice room and disconnects everyone in it; one already gone is fine. */
    public function deleteRoom(string $name): void
    {
        $this->call('DeleteRoom', ['room' => $name], ['roomCreate' => true], $name, notFoundIsFine: true);
    }

    /** Disconnects one participant; one not there is fine. A token of theirs still joins: the epoch is the bar. */
    public function removeParticipant(string $name, string $identity): void
    {
        $this->call('RemoveParticipant', ['room' => $name, 'identity' => $identity], ['roomAdmin' => true, 'room' => $name], $name, notFoundIsFine: true);
    }

    /**
     * Gives a participant the whole permission set again, with or without
     * the microphone: the call replaces every permission, so none is left
     * out. Taking it unpublishes the microphone at once; giving it back
     * opens nothing. One not there is fine.
     */
    public function updateParticipant(string $name, string $identity, bool $canPublish): void
    {
        $this->call('UpdateParticipant', [
            'room' => $name,
            'identity' => $identity,
            'permission' => [
                'can_subscribe' => true,
                'can_publish' => $canPublish,
                'can_publish_data' => false,
                'can_publish_sources' => ['MICROPHONE'],
                'can_update_metadata' => false,
                'hidden' => false,
                'recorder' => false,
            ],
        ], ['roomAdmin' => true, 'room' => $name], $name, notFoundIsFine: true);
    }

    /**
     * The voice rooms there are, by name, each with how many are in it
     * (`num_participants`, a 32-bit number protojson writes as a number):
     * the sweep reads the names, `JoinRoomVoice` the voice rooms in use
     * (ADR 031 decision 38, as amended 2026-11-19).
     *
     * @return array<string, int>
     */
    public function rooms(): array
    {
        $answer = $this->call('ListRooms', ['names' => []], ['roomList' => true]);
        $rooms = [];

        foreach (is_array($answer['rooms'] ?? null) ? $answer['rooms'] : [] as $room) {
            if (is_array($room) && is_string($room['name'] ?? null)) {
                $count = $room['num_participants'] ?? 0;
                $rooms[$room['name']] = (is_int($count) || (is_string($count) && ctype_digit($count))) ? (int) $count : 0;
            }
        }

        return $rooms;
    }

    /**
     * Who is in a voice room, by identity, whether each may publish, and
     * when each joined (the voice server's `joined_at_ms`, Unix
     * milliseconds: a session the library resumed keeps the moment it
     * first joined, a new join — with a ticket or a refreshed token — gets
     * a new one). Null when the answer has no moment.
     *
     * @return list<array{identity: string, can_publish: bool, joined_at_ms: int|null}>
     */
    public function participants(string $name): array
    {
        $answer = $this->call('ListParticipants', ['room' => $name], ['roomAdmin' => true, 'room' => $name], $name);
        $participants = [];

        foreach (is_array($answer['participants'] ?? null) ? $answer['participants'] : [] as $participant) {
            if (! is_array($participant) || ! is_string($participant['identity'] ?? null)) {
                continue;
            }

            $permission = is_array($participant['permission'] ?? null) ? $participant['permission'] : [];
            $participants[] = [
                'identity' => $participant['identity'],
                'can_publish' => ($permission['can_publish'] ?? false) === true,
                'joined_at_ms' => self::joinedAtMs($participant),
            ];
        }

        return $participants;
    }

    /**
     * A participant's `joined_at_ms` — protojson writes 64-bit numbers as
     * strings — or its `joined_at` in seconds; null when neither is there.
     *
     * @param  array<array-key, mixed>  $participant
     */
    private static function joinedAtMs(array $participant): ?int
    {
        $ms = $participant['joined_at_ms'] ?? null;

        if ((is_string($ms) || is_int($ms)) && preg_match('/^[1-9]\d{0,15}$/', (string) $ms) === 1) {
            return (int) $ms;
        }

        $seconds = $participant['joined_at'] ?? null;

        if ((is_string($seconds) || is_int($seconds)) && preg_match('/^[1-9]\d{0,12}$/', (string) $seconds) === 1) {
            return (int) $seconds * 1000;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body  always an object on the wire
     * @param  array<string, bool|string>  $grant
     * @return array<array-key, mixed>
     *
     * @throws RoomVoiceUnreachable
     */
    private function call(string $call, array $body, array $grant, ?string $voiceRoom = null, bool $notFoundIsFine = false): array
    {
        $timeout = Config::integer('elifba.rooms.voice.timeout_ms') / 1000;

        try {
            $response = Http::baseUrl(rtrim(Config::string('elifba.rooms.voice.api_url'), '/'))
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->acceptJson()
                ->withToken($this->token($grant))
                ->post("/twirp/livekit.RoomService/{$call}", $body);
        } catch (ConnectionException) {
            throw new RoomVoiceUnreachable($call, $voiceRoom, ConnectionException::class);
        } catch (Throwable $failure) {
            throw new RoomVoiceUnreachable($call, $voiceRoom, $failure::class);
        }

        if ($notFoundIsFine && self::notFound($response)) {
            return [];
        }

        $answer = $response->successful() ? $response->json() : null;

        if (! is_array($answer)) {
            throw new RoomVoiceUnreachable($call, $voiceRoom, 'HTTP '.$response->status());
        }

        return $answer;
    }

    private static function notFound(Response $response): bool
    {
        return $response->status() === 404 && $response->json('code') === 'not_found';
    }

    /**
     * A token for one call: HS256 with the voice secret, `iss` the key,
     * living 30 seconds, with only the grant the call needs.
     *
     * @param  array<string, bool|string>  $grant
     */
    private function token(array $grant): string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return JWT::encode([
            'iss' => Config::string('elifba.rooms.voice.key'),
            'sub' => 'elifba',
            'nbf' => $now,
            'exp' => $now + self::CALL_TOKEN_SECONDS,
            'video' => $grant,
        ], Config::string('elifba.rooms.voice.secret'), 'HS256');
    }
}
