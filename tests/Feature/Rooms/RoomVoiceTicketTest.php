<?php

use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Actions\SetHostPlays;
use App\Domain\Rooms\Actions\SetRoomPlayerVoice;
use App\Domain\Rooms\Actions\SetRoomVoice;
use App\Domain\Rooms\Data\HostPlaysData;
use App\Domain\Rooms\Data\RoomPlayerVoiceData;
use App\Domain\Rooms\Data\RoomVoiceSwitchData;
use App\Domain\Rooms\Enums\RoomVoiceStanding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomVoiceTicket;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeVoiceServer;
use Tests\Support\Rooms;

/*
 * A ticket to a room's live voice (docs/architecture.md §12 "Live voice" —
 * "The ticket", ADR 031 decisions 6, 14 and 18–21): only the host, playing
 * or not, and a present player the host let in, of an open room whose
 * voice is on, after the account's agreement; a JWT that names the player
 * by `room_players.id` alone and writes out every permission — the
 * microphone only, and only for an `open` standing — for a minute; a 503
 * and nothing stamped when the voice server does not answer; never a
 * ticket or a name in a log. Every grant is read back with the library.
 */

beforeEach(function (): void {
    $this->travelTo('2026-11-24 10:00:00');
    $this->voice = Rooms::voiceOn($this->reverb);
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', $this->host->id)->sole();
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova', 'email' => 'aygun@example.test']);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(true));
    $this->room->refresh();
});

/**
 * @param  array<string, mixed>  $body
 */
function askTicket(User $account, Room $room, array $body = ['agree' => true]): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.voice.tickets.store', $room), $body);
}

/**
 * The ticket's claims, verified with the instance's secret at the app's clock.
 *
 * @return array<string, mixed>
 */
function ticketClaims(string $token): array
{
    JWT::$timestamp = CarbonImmutable::now()->getTimestamp();

    try {
        $claims = JWT::decode($token, new Key(FakeVoiceServer::SECRET, 'HS256'));
    } finally {
        JWT::$timestamp = null;
    }

    /** @var array<string, mixed> */
    return json_decode((string) json_encode($claims), true);
}

it('gives a ticket to the host and to an admitted present player, and to nobody else', function (): void {
    askTicket($this->host, $this->room)->assertOk()->assertJsonStructure(['url', 'token']);
    askTicket($this->aygun, $this->room)->assertOk();

    // A newcomer who waits; a player who left; a removed player; an account outside the room.
    $newcomer = User::factory()->player()->create();
    $newcomerRow = Rooms::join($this->room, $newcomer);
    $leaver = User::factory()->player()->create();
    $leaverRow = Rooms::join($this->room, $leaver);
    app(SetRoomPlayerVoice::class)->handle($this->room, $leaverRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    app(LeaveRoom::class)->handle($this->room, $leaverRow);
    $removed = User::factory()->player()->create();
    $removedRow = Rooms::join($this->room, $removed);
    app(SetRoomPlayerVoice::class)->handle($this->room, $removedRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    app(RemoveRoomPlayer::class)->handle($this->room, $removedRow);

    askTicket($newcomer, $this->room)->assertForbidden();
    askTicket($leaver, $this->room)->assertForbidden();
    askTicket($removed, $this->room)->assertNotFound();
    askTicket(User::factory()->player()->create(), $this->room)->assertNotFound();
    auth()->guard('web')->forgetUser();
    $this->postJson(route('rooms.voice.tickets.store', $this->room), ['agree' => true])->assertUnauthorized();

    // Let in, the newcomer gets one; a host who stepped out of playing keeps theirs.
    app(SetRoomPlayerVoice::class)->handle($this->room, $newcomerRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    askTicket($newcomer, $this->room)->assertOk();
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(false));
    app(SetHostPlays::class)->handle($this->room, new HostPlaysData(false));
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(true));
    expect(ticketClaims(askTicket($this->host, $this->room)->assertOk()->json('token'))['sub'])->toBe($this->hostRow->id);

    // The voice off: nobody.
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(false));
    askTicket($this->host, $this->room)->assertForbidden();
    askTicket($this->aygun, $this->room)->assertForbidden();

    // An idle room, the voice on: nobody.
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(true));
    $this->travel(Config::integer('elifba.rooms.idle_hours') * 60 + 1)->minutes();
    askTicket($this->host, $this->room)->assertForbidden();
    askTicket($this->aygun, $this->room)->assertForbidden();

    // A closed room: nobody.
    $this->travelTo('2026-11-24 10:00:00');
    $this->room->forceFill(['last_activity_at' => now()])->save();
    askTicket($this->aygun, $this->room)->assertOk();
    app(CloseRoom::class)->handle($this->room);
    askTicket($this->host, $this->room)->assertForbidden();
    askTicket($this->aygun, $this->room)->assertForbidden();
});

it('gives a ticket only for the voice room as it is now', function (): void {
    $other = Rooms::join($this->room);
    app(SetRoomPlayerVoice::class)->handle($this->room, $other, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    $this->voice->sent = [];

    // The host removes a player while the voice server is making the voice room for Aygün's ticket.
    $this->voice->during('CreateRoom', fn () => app(RemoveRoomPlayer::class)->handle($this->room, $other));
    $token = (string) askTicket($this->aygun, $this->room)->assertOk()->json('token');

    // The voice room made for the old epoch goes again, and the ticket is for the new one.
    expect(array_map(static fn (array $sent): array => [$sent['call'], $sent['body']['name'] ?? $sent['body']['room']], $this->voice->sent))->toBe([
        ['CreateRoom', $this->room->id.'.0'],
        ['DeleteRoom', $this->room->id.'.0'],
        ['DeleteRoom', $this->room->id.'.0'],
        ['CreateRoom', $this->room->id.'.1'],
    ])
        ->and(ticketClaims($token)['video']['room'])->toBe($this->room->id.'.1')
        ->and(array_keys($this->voice->rooms))->toBe([$this->room->id.'.1']);

    // The voice turned off meanwhile: no ticket, and no voice room left behind.
    $this->voice->during('CreateRoom', fn () => app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(false)));
    askTicket($this->aygun, $this->room)->assertForbidden();
    expect($this->voice->rooms)->toBe([]);
});

it('asks for the agreement once and stamps it', function (): void {
    askTicket($this->aygun, $this->room, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['agree' => __('rooms.voice.errors.agreement')]);
    askTicket($this->aygun, $this->room, ['agree' => false])->assertJsonValidationErrors(['agree' => __('rooms.voice.errors.agreement')]);
    expect($this->aygun->refresh()->voice_agreed_at)->toBeNull()
        ->and($this->voice->calls('CreateRoom'))->toHaveCount(1)
        ->and($this->getJson(route('rooms.state', $this->room))->json('voice'))->toBe(['on' => true, 'agreed' => false]);

    askTicket($this->aygun, $this->room)->assertOk();
    expect($this->aygun->refresh()->voice_agreed_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and($this->getJson(route('rooms.state', $this->room))->json('voice'))->toBe(['on' => true, 'agreed' => true]);

    // Once per account: later joins need no `agree`, and the stamp stays the first.
    $this->travel(1)->hours();
    askTicket($this->aygun, $this->room, [])->assertOk();
    expect($this->aygun->refresh()->voice_agreed_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        // The player role's own sentence and stamp are another thing, untouched.
        ->and($this->aygun->player_agreed_at)->not->toBeNull();
});

it('names the player by the row\'s id and by nothing else', function (): void {
    $response = askTicket($this->aygun, $this->room)->assertOk();
    $token = (string) $response->json('token');
    $claims = ticketClaims($token);
    $payload = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true);

    expect($response->json())->toBe(['url' => FakeVoiceServer::URL, 'token' => $token])
        ->and(array_keys($claims))->toBe(['iss', 'sub', 'nbf', 'exp', 'video'])
        ->and($claims['iss'])->toBe(FakeVoiceServer::KEY)
        ->and($claims['sub'])->toBe($this->aygunRow->id)
        ->and($payload)->not->toContain('Aygün')
        ->and($payload)->not->toContain('aygun@example.test')
        ->and($payload)->not->toContain('"'.$this->aygun->id.'"');

    foreach (['name', 'metadata', 'attributes'] as $absent) {
        expect(array_key_exists($absent, $claims))->toBeFalse($absent)
            ->and(array_key_exists($absent, $claims['video']))->toBeFalse("video.{$absent}");
    }
});

it('writes out every permission and grants a microphone only', function (): void {
    $video = ticketClaims((string) askTicket($this->aygun, $this->room)->json('token'))['video'];
    $expected = [
        'room' => $this->room->id.'.0',
        'roomJoin' => true,
        'canSubscribe' => true,
        'canPublish' => true,
        'canPublishData' => false,
        'canPublishSources' => ['microphone'],
        'canUpdateOwnMetadata' => false,
    ];

    // Key by key, since the voice server grants what a token leaves out.
    foreach ($expected as $key => $value) {
        expect($video[$key] ?? 'missing')->toBe($value, $key);
    }

    expect($video)->toBe($expected);

    // A new epoch is a new voice room: the next ticket names it.
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(false));
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(true));
    expect(ticketClaims((string) askTicket($this->aygun, $this->room)->json('token'))['video']['room'])->toBe($this->room->id.'.1');
});

it('grants a muted player no microphone', function (): void {
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    $video = ticketClaims((string) askTicket($this->aygun, $this->room)->assertOk()->json('token'))['video'];

    expect($video)->toBe([
        'room' => $this->room->id.'.0',
        'roomJoin' => true,
        'canSubscribe' => true,
        'canPublish' => false,
        'canPublishData' => false,
        'canPublishSources' => ['microphone'],
        'canUpdateOwnMetadata' => false,
    ]);

    // Let speak again, the next ticket may publish; the host's is never muted.
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    expect(ticketClaims((string) askTicket($this->aygun, $this->room)->json('token'))['video']['canPublish'])->toBeTrue()
        ->and(ticketClaims((string) askTicket($this->host, $this->room)->json('token'))['video']['canPublish'])->toBeTrue();
});

it('never grants create, admin, list, record or hidden', function (): void {
    $muted = User::factory()->player()->create();
    $mutedRow = Rooms::join($this->room, $muted);
    app(SetRoomPlayerVoice::class)->handle($this->room, $mutedRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));

    foreach ([$this->host, $this->aygun, $muted] as $account) {
        $claims = ticketClaims((string) askTicket($account, $this->room)->assertOk()->json('token'));

        foreach (['roomCreate', 'roomAdmin', 'roomList', 'roomRecord', 'hidden', 'recorder', 'agent', 'ingressAdmin'] as $grant) {
            expect(array_key_exists($grant, $claims['video']))->toBeFalse($grant)
                ->and(array_key_exists($grant, $claims))->toBeFalse($grant);
        }
    }
});

it('lives a minute', function (): void {
    $claims = ticketClaims((string) askTicket($this->aygun, $this->room)->json('token'));

    expect($claims['nbf'])->toBe(CarbonImmutable::parse('2026-11-24 10:00:00')->getTimestamp())
        ->and($claims['exp'] - $claims['nbf'])->toBe(60);

    // A minute later it no longer verifies.
    $token = (string) askTicket($this->aygun, $this->room)->json('token');
    $this->travel(61)->seconds();
    expect(fn () => ticketClaims($token))->toThrow(ExpiredException::class);
});

it('answers 503 and stamps nothing when the voice server is down', function (): void {
    $this->voice->down();
    $revision = $this->room->revision;
    $seen = $this->aygunRow->refresh()->seen_ms;

    askTicket($this->aygun, $this->room)
        ->assertStatus(503)
        ->assertExactJson(['status' => 'unavailable'])
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($this->aygun->refresh()->voice_agreed_at)->toBeNull()
        ->and($this->voice->calls('CreateRoom'))->toHaveCount(2)
        ->and(end($this->voice->sent)['answered'] ?? null)->toBeFalse()
        // A ticket raises no revision and is not a read.
        ->and($this->room->refresh()->revision)->toBe($revision)
        ->and($this->aygunRow->refresh()->seen_ms)->toBe($seen);
});

it('puts no ticket and no name in a log', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $context = array_map(static fn (mixed $value): string => $value instanceof Throwable ? (string) $value : (string) json_encode($value), $event->context);
        $logged[] = $event->message.' '.implode(' ', $context);
    });

    $answer = askTicket($this->aygun, $this->room)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'application/json');
    $token = (string) $answer->json('token');
    $revision = $this->room->refresh()->revision;

    // Never in the session or a redirect, and a ticket raises no revision.
    expect($answer->isRedirect())->toBeFalse()
        ->and(json_encode(session()->all()))->not->toContain($token)
        ->and($this->room->revision)->toBe($revision);

    // The voice server down: a refused ticket and a cut that did not arrive are reported.
    $this->voice->down();
    askTicket($this->aygun, $this->room)->assertStatus(503);
    app(RemoveRoomPlayer::class)->handle($this->room, $this->aygunRow);

    $log = implode("\n", $logged);
    expect($logged)->not->toBe([])
        ->and($log)->toContain($this->room->id)
        ->and($log)->not->toContain($token)
        ->and($log)->not->toContain(explode('.', $token)[1])
        ->and($log)->not->toContain('Aygün')
        ->and($log)->not->toContain('Rəşad')
        ->and($log)->not->toContain('aygun@example.test')
        ->and($log)->not->toContain(FakeVoiceServer::SECRET);
});

it('reports a voice server that does not answer without the account', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });
    $this->voice->down();

    // A refused ticket, a mute and a leave whose cuts did not arrive.
    askTicket($this->aygun, $this->room)->assertStatus(503);
    $this->actingAs($this->host)->putJson(route('rooms.members.voice.update', ['room' => $this->room, 'member' => $this->aygunRow]), ['standing' => 'muted'])->assertNoContent();
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));

    expect($logged)->toHaveCount(3)
        ->and(array_map(static fn (MessageLogged $event): string => $event->level, $logged))->toBe(['warning', 'error', 'error']);

    foreach ($logged as $event) {
        // The room by its id, and nothing that says whose request it was.
        expect($event->message)->toContain($this->room->id)
            ->and($event->context)->toBe([]);
    }
});

it('refuses an admitted player who is no longer verified or no longer has the player role', function (): void {
    $calls = count($this->voice->sent);

    $this->aygun->forceFill(['email_verified_at' => null])->save();
    askTicket($this->aygun, $this->room)->assertForbidden();
    $this->aygun->forceFill(['email_verified_at' => now()])->save();

    // No role at all: the role's choice first; another role: not the player group.
    $this->aygun->removeRole(Role::Player->value);
    $this->aygun->forceFill(['area' => null])->save();
    expect(askTicket($this->aygun->refresh(), $this->room)->status())->toBe(409);
    $this->aygun->assignRole(Role::Teacher->value);
    askTicket($this->aygun->refresh(), $this->room)->assertForbidden();

    expect($this->voice->sent)->toHaveCount($calls);
});

it('keeps a ticket inside its own room for an account in two rooms', function (): void {
    $otherHost = User::factory()->player()->create();
    $roomB = Rooms::open($otherHost);
    app(SetRoomVoice::class)->handle($roomB, new RoomVoiceSwitchData(true));
    // Let in to the first room, waiting in the second.
    $aygunInB = Rooms::join($roomB, $this->aygun);
    $calls = count($this->voice->sent);

    askTicket($this->aygun, $roomB->refresh())->assertForbidden();
    expect($this->voice->sent)->toHaveCount($calls);

    $a = ticketClaims((string) askTicket($this->aygun, $this->room)->assertOk()->json('token'));
    expect($a['sub'])->toBe($this->aygunRow->id)
        ->and($a['video']['room'])->toBe($this->room->id.'.0');

    app(SetRoomPlayerVoice::class)->handle($roomB, $aygunInB, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    $b = ticketClaims((string) askTicket($this->aygun, $roomB->refresh())->assertOk()->json('token'));
    expect($b['sub'])->toBe($aygunInB->id)
        ->and($b['video']['room'])->toBe($roomB->id.'.0');

    // Each host reaches only their own room, and a row of the other room is no member here.
    askTicket($this->host, $roomB)->assertNotFound();
    askTicket($otherHost, $this->room)->assertNotFound();
    $this->actingAs($this->host)->putJson(route('rooms.members.voice.update', ['room' => $this->room, 'member' => $aygunInB]), ['standing' => 'muted'])->assertNotFound();
    expect($aygunInB->refresh()->voice_muted_at)->toBeNull();

    // And the ticket itself is for a row of its own room only.
    expect(fn () => RoomVoiceTicket::for($this->room, $aygunInB))->toThrow(LogicException::class);
});

it('gives twelve tickets a minute to an account, refusals counted, and nobody else\'s', function (): void {
    foreach (range(1, 12) as $try) {
        askTicket($this->aygun, $this->room)->assertOk();
    }

    askTicket($this->aygun, $this->room)->assertTooManyRequests();
    askTicket($this->host, $this->room)->assertOk();

    $newcomer = User::factory()->player()->create(['voice_agreed_at' => now()]);
    Rooms::join($this->room, $newcomer);

    foreach (range(1, 12) as $try) {
        askTicket($newcomer, $this->room)->assertForbidden();
    }

    askTicket($newcomer, $this->room)->assertTooManyRequests();

    $this->travel(61)->seconds();
    askTicket($this->aygun, $this->room)->assertOk();
});

it('keeps an account\'s count of tickets no longer than its minute and one more', function (): void {
    // The instance's cache is the database (docker-compose.yml): the limiter's two rows land there.
    $limiter = new RateLimiter(Cache::store('database'));
    $key = md5('room-voice'.$this->aygun->id);
    $limiter->hit($key, 60);
    Cache::store('database')->forever('elifba-kept', 'kept');
    $rows = static fn (): int => DB::table('cache')->where('key', 'like', '%'.$key.'%')->count();

    expect($rows())->toBe(2);

    // Still counting within its minute.
    $this->travel(59)->seconds();
    $this->artisan('cache:prune-expired')->assertSuccessful();
    expect($rows())->toBe(2);

    // Gone at the next run after it, whether or not the account asks again; a row kept forever stays.
    $this->travel(2)->seconds();
    $this->artisan('cache:prune-expired')->expectsOutput('Deleted 2 expired cache rows.');
    expect($rows())->toBe(0)
        ->and(Cache::store('database')->get('elifba-kept'))->toBe('kept');

    // Every minute, by the scheduler that already runs.
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (Illuminate\Console\Scheduling\Event $event): bool => str_contains((string) $event->command, 'cache:prune-expired'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
