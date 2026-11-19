<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tests never call a real voice server (docs/architecture.md §12 "Live
 * voice"). This answers the Twirp calls of `livekit.RoomService` on
 * `elifba.rooms.voice.api_url` as LiveKit v1.13.11 answered them under
 * `composer dev:voice` on 2026-11-19 (C3, `~/elifba-shots/voice/c3/`):
 * protojson with the proto's field names, 64-bit numbers as strings,
 * every field present; `{code, msg}` for an error — 401 `unauthenticated`
 * "permissions denied" for a token without the call's grant (`roomCreate`
 * for `CreateRoom` and `DeleteRoom`, `roomList` for `ListRooms`,
 * `roomAdmin` with the room for the participant calls), 404 `not_found`
 * for a room or a participant that is not there, 400 `malformed` for a
 * body that is not an object. `CreateRoom` of a room that exists answers
 * that room; `ListParticipants` of a room that does not exist answers an
 * empty list.
 *
 * It keeps the voice rooms the app made and who is "in" them — a test puts
 * someone in with `joins()`, as a page holding a ticket would — and
 * records every call in `sent`, with the transaction level and the number
 * of broadcasts at that moment, so a test can say the call came after the
 * commit and after `room.changed`. `down()` makes every call fail as a
 * server that does not answer; a call tried while it is down is recorded
 * too, with `answered` false.
 */
final class FakeVoiceServer
{
    public const string KEY = 'elifba-test-voice';

    public const string SECRET = 'elifba-test-voice-secret-longer-than-32-bytes-0123456789';

    public const string URL = 'wss://voice.elifba.test';

    public const string API_URL = 'http://voice.elifba.test:7880';

    /**
     * @var list<array{call: string, body: array<string, mixed>, grant: array<string, mixed>, level: int, broadcasts: int|null, answered: bool}>
     */
    public array $sent = [];

    /**
     * The voice rooms there are, by name: who is in each, and whether they may publish.
     *
     * @var array<string, array<string, bool>>
     */
    public array $rooms = [];

    /**
     * When each participant joined, Unix milliseconds, by voice room and identity.
     *
     * @var array<string, array<string, int>>
     */
    private array $joinedAt = [];

    private bool $down = false;

    /** @var array<string, Closure(): void> what happens at the voice server while a call of that name is answered, once */
    private array $during = [];

    private function __construct(private readonly ?FakeReverb $reverb) {}

    public static function install(?FakeReverb $reverb = null): self
    {
        $fake = new self($reverb);

        Http::fake([self::API_URL.'/*' => static fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** From now on every call fails as if the voice server did not answer. */
    public function down(): void
    {
        $this->down = true;
    }

    /**
     * Runs `$meanwhile` once, while the next call of `$call` is being
     * answered — a fact that lands between a request and its answer.
     *
     * @param  Closure(): void  $meanwhile
     */
    public function during(string $call, Closure $meanwhile): void
    {
        $this->during[$call] = $meanwhile;
    }

    /**
     * Someone with a ticket is in the voice room, publishing or not, from
     * this moment of the app's clock (a test may have travelled): the
     * voice server's `joined_at_ms`, which a new join renews.
     */
    public function joins(string $room, string $identity, bool $canPublish = true): void
    {
        $this->rooms[$room][$identity] = $canPublish;
        $this->joinedAt[$room][$identity] = CarbonImmutable::now()->getTimestampMs();
    }

    /**
     * The calls made so far, of one kind or all.
     *
     * @return list<array{call: string, body: array<string, mixed>, grant: array<string, mixed>, level: int, broadcasts: int|null, answered: bool}>
     */
    public function calls(?string $call = null): array
    {
        return array_values(array_filter($this->sent, static fn (array $sent): bool => $call === null || $sent['call'] === $call));
    }

    private function handle(Request $request): PromiseInterface
    {
        $call = str_replace('/twirp/livekit.RoomService/', '', (string) parse_url($request->url(), PHP_URL_PATH));
        $grant = $this->grant($request);
        $body = json_decode($request->body(), true);
        $malformed = ! is_array($body) || str_starts_with(ltrim($request->body()), '[');

        $this->sent[] = [
            'call' => $call,
            'body' => is_array($body) && ! $malformed ? $body : [],
            'grant' => $grant ?? [],
            'level' => DB::transactionLevel(),
            'broadcasts' => $this->reverb === null ? null : count($this->reverb->sent),
            'answered' => ! $this->down,
        ];

        if ($this->down) {
            return Factory::failedConnection()($request);
        }

        if ($malformed) {
            return self::error(400, 'malformed', 'the json request could not be decoded');
        }

        if (isset($this->during[$call])) {
            $meanwhile = $this->during[$call];
            unset($this->during[$call]);
            $meanwhile();
        }

        /** @var array<string, mixed> $body */
        if ($grant === null || ! self::allows($call, $grant, $body)) {
            return self::error(401, 'unauthenticated', 'permissions denied');
        }

        return match ($call) {
            'CreateRoom' => $this->createRoom($body),
            'DeleteRoom' => $this->deleteRoom($body),
            'ListRooms' => Http::response(['rooms' => array_map(fn (string $name): array => $this->roomInfo($name), array_keys($this->rooms))]),
            'ListParticipants' => Http::response(['participants' => $this->participantsOf((string) $body['room'])]),
            'RemoveParticipant' => $this->removeParticipant($body),
            'UpdateParticipant' => $this->updateParticipant($body),
            default => self::error(404, 'bad_route', 'no handler for path'),
        };
    }

    /**
     * The token's grant, checked as the voice server checks it; null when it does not verify.
     *
     * @return array<string, mixed>|null
     */
    private function grant(Request $request): ?array
    {
        $header = $request->header('Authorization')[0] ?? '';

        // The voice server's clock is the app's here: a test may have travelled.
        JWT::$timestamp = CarbonImmutable::now()->getTimestamp();

        try {
            $claims = JWT::decode(str_replace('Bearer ', '', $header), new Key(self::SECRET, 'HS256'));
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$timestamp = null;
        }

        /** @var array{iss?: string, video?: array<string, mixed>} $decoded */
        $decoded = json_decode((string) json_encode($claims), true);

        return ($decoded['iss'] ?? null) === self::KEY ? ($decoded['video'] ?? []) : null;
    }

    /**
     * @param  array<string, mixed>  $grant
     * @param  array<string, mixed>  $body
     */
    private static function allows(string $call, array $grant, array $body): bool
    {
        return match ($call) {
            'CreateRoom', 'DeleteRoom' => ($grant['roomCreate'] ?? false) === true,
            'ListRooms' => ($grant['roomList'] ?? false) === true,
            default => ($grant['roomAdmin'] ?? false) === true && ($grant['room'] ?? null) === ($body['room'] ?? ''),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function createRoom(array $body): PromiseInterface
    {
        $name = (string) $body['name'];
        $this->rooms[$name] ??= [];

        return Http::response($this->roomInfo($name, $body));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function deleteRoom(array $body): PromiseInterface
    {
        $name = (string) $body['room'];

        if (! isset($this->rooms[$name])) {
            return self::error(404, 'not_found', 'requested room does not exist');
        }

        unset($this->rooms[$name], $this->joinedAt[$name]);

        return Http::response([]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function removeParticipant(array $body): PromiseInterface
    {
        $name = (string) $body['room'];
        $identity = (string) $body['identity'];

        if (! isset($this->rooms[$name][$identity])) {
            return self::error(404, 'not_found', 'participant does not exist');
        }

        unset($this->rooms[$name][$identity], $this->joinedAt[$name][$identity]);

        return Http::response([]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function updateParticipant(array $body): PromiseInterface
    {
        $name = (string) $body['room'];
        $identity = (string) $body['identity'];

        if (! isset($this->rooms[$name][$identity])) {
            return self::error(404, 'not_found', 'participant does not exist');
        }

        /** @var array{can_publish?: bool} $permission */
        $permission = is_array($body['permission'] ?? null) ? $body['permission'] : [];
        $this->rooms[$name][$identity] = ($permission['can_publish'] ?? false) === true;

        return Http::response($this->participantInfo($identity, $this->rooms[$name][$identity], $this->joinedAt[$name][$identity] ?? null));
    }

    /**
     * @param  array<string, mixed>  $created
     * @return array<string, mixed>
     */
    private function roomInfo(string $name, array $created = []): array
    {
        return [
            'sid' => 'RM_'.substr(md5($name), 0, 12),
            'name' => $name,
            'empty_timeout' => $created['empty_timeout'] ?? 300,
            'departure_timeout' => $created['departure_timeout'] ?? 20,
            'max_participants' => $created['max_participants'] ?? 0,
            'creation_time' => '1795033105',
            'creation_time_ms' => '1795033105948',
            'turn_password' => '',
            'enabled_codecs' => [['mime' => 'audio/opus', 'fmtp_line' => ''], ['mime' => 'audio/red', 'fmtp_line' => '']],
            'metadata' => '',
            'num_participants' => count($this->rooms[$name] ?? []),
            'num_publishers' => count(array_filter($this->rooms[$name] ?? [])),
            'active_recording' => false,
            'version' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function participantsOf(string $name): array
    {
        $participants = [];

        foreach ($this->rooms[$name] ?? [] as $identity => $canPublish) {
            $participants[] = $this->participantInfo((string) $identity, $canPublish, $this->joinedAt[$name][(string) $identity] ?? null);
        }

        return $participants;
    }

    /**
     * A participant as the voice server lists one: never a name of ours (`name` is empty).
     *
     * @return array<string, mixed>
     */
    private function participantInfo(string $identity, bool $canPublish, ?int $joinedAtMs = null): array
    {
        $joinedAtMs ??= 1795034002954;

        return [
            'sid' => 'PA_'.substr(md5($identity), 0, 12),
            'identity' => $identity,
            'state' => 'ACTIVE',
            'tracks' => [],
            'metadata' => '',
            'joined_at' => (string) intdiv($joinedAtMs, 1000),
            'joined_at_ms' => (string) $joinedAtMs,
            'name' => '',
            'version' => 2,
            'permission' => [
                'can_subscribe' => true,
                'can_publish' => $canPublish,
                'can_publish_data' => false,
                'can_publish_sources' => ['MICROPHONE'],
                'hidden' => false,
                'recorder' => false,
                'can_update_metadata' => false,
                'agent' => false,
                'can_subscribe_metrics' => false,
                'can_manage_agent_session' => false,
            ],
            'region' => '',
            'is_publisher' => $canPublish,
            'kind' => 'STANDARD',
            'attributes' => (object) [],
            'disconnect_reason' => 'UNKNOWN_REASON',
            'kind_details' => [],
            'data_tracks' => [],
            'client_protocol' => 2,
            'capabilities' => ['CAP_COMPRESSION_DEFLATE_RAW'],
        ];
    }

    private static function error(int $status, string $code, string $message): PromiseInterface
    {
        return Http::response(['code' => $code, 'msg' => $message], $status);
    }
}
