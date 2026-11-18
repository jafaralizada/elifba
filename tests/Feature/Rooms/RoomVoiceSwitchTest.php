<?php

use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Actions\SweepRoomVoices;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomVoiceServer;
use App\Http\Middleware\EnsureRoomsAreEnabled;
use App\Http\Middleware\EnsureRoomVoiceIsEnabled;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeVoiceServer;
use Tests\Support\Rooms;

/*
 * The live voice's switch (docs/architecture.md §12 "Live voice" — "The
 * switch", ADR 031 decision 28): `elifba.rooms.voice.enabled` is off
 * unless the instance sets `ELIFBA_ROOMS_VOICE`, `RoomsArea::voice()` is
 * its one reader and counts it on only with the area on and the voice
 * server fully named, and `Tests\TestCase` leaves it off with no server.
 * While it is off every voice route is a 404 to everyone, before the login
 * could say it exists, and no state, privacy or welcome page says a word of
 * voice. The voice routes are those that carry `rooms.voice`.
 */

/**
 * Every route behind the voice's switch, by name.
 *
 * @return array<string, RoutingRoute>
 */
function voiceRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array('rooms.voice', $route->gatherMiddleware(), true)) {
            $routes[(string) $route->getName()] = $route;
        }
    }

    return $routes;
}

/**
 * What a refused request could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function voiceSwitchSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['users' => $rows('users'), 'rooms' => $rows('rooms'), 'room_players' => $rows('room_players')];
}

it('answers 404 on every voice route while the voice switch is off', function (): void {
    Rooms::on();
    Http::fake();
    $host = User::factory()->player()->create();
    $room = Rooms::open($host);
    $player = User::factory()->player()->create();
    $row = Rooms::join($room, $player);
    // A room left with its voice on, as if the switch went off under it.
    $room->forceFill(['voice_at' => now()])->save();
    $values = ['room' => $room->id, 'member' => $row->id];
    $routes = voiceRoutes();
    $before = voiceSwitchSnapshot();

    expect(array_keys($routes))->toEqualCanonicalizing(['rooms.voice.update', 'rooms.members.voice.update', 'rooms.voice.tickets.store']);

    // Off; then on but with the voice server not fully named.
    foreach ([[], ['elifba.rooms.voice.enabled' => true, 'elifba.rooms.voice.key' => FakeVoiceServer::KEY, 'elifba.rooms.voice.secret' => FakeVoiceServer::SECRET, 'elifba.rooms.voice.url' => FakeVoiceServer::URL]] as $config) {
        Config::set($config);

        foreach ($routes as $name => $route) {
            $uri = Rooms::uri($route, $values);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                // A visitor first: not even the login says that the route exists.
                $this->app['auth']->guard('web')->forgetUser();
                expect($this->json($method, $uri, ['on' => true, 'standing' => 'muted', 'agree' => true])->status())->toBe(404, "{$method} {$name}, a visitor");

                foreach (['the host' => $host, 'a player' => $player] as $who => $account) {
                    expect($this->actingAs($account)->json($method, $uri, ['on' => true, 'standing' => 'muted', 'agree' => true])->status())->toBe(404, "{$method} {$name}, {$who}")
                        ->and($this->actingAs($account)->call($method, $uri)->status())->toBe(404, "{$method} {$name}, {$who} (page)");
                }
            }
        }
    }

    expect(voiceSwitchSnapshot())->toBe($before);
    Http::assertNothingSent();

    // The voice's switch runs right after the area's, before anything else of the route.
    app(Kernel::class);
    $router = app('router');

    foreach ($routes as $name => $route) {
        $middleware = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

        expect(array_slice($middleware, 0, 2))->toBe([EnsureRoomsAreEnabled::class, EnsureRoomVoiceIsEnabled::class], $name);
    }
});

it('says nothing of voice in the state, the privacy page or the welcome page while it is off', function (): void {
    Rooms::on();
    $host = User::factory()->player()->create(['voice_agreed_at' => now()]);
    $room = Rooms::open($host);
    $row = Rooms::join($room);
    RoomPlayer::query()->whereKey($row->id)->update(['voice_admitted_at' => now(), 'voice_muted_at' => now()]);
    $room->forceFill(['voice_at' => now(), 'voice_epoch' => 3])->save();

    $state = $this->actingAs($host)->getJson(route('rooms.state', $room))->assertOk()->json();
    expect($state['voice'])->toBeNull()
        ->and(array_unique(array_column($state['players'], 'voice')))->toBe([null]);
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('state.voice', null));
    $this->get(route('rooms.screen', $room))->assertInertia(fn (Assert $page) => $page->where('state.voice', null));

    auth()->guard('web')->forgetUser();
    $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('rooms', ['idleHours' => Config::integer('elifba.rooms.idle_hours')]));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->missing('rooms.voice')->has('rooms.maxPlayers'));

    // The switch on: the same pages say it.
    Rooms::voiceOn();
    $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('rooms', ['idleHours' => Config::integer('elifba.rooms.idle_hours'), 'voice' => true]));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('rooms.voice', true));
    expect($this->actingAs($host)->getJson(route('rooms.state', $room))->json('voice'))->toBe(['on' => true, 'agreed' => true]);

    // A closed room says nothing of it, the switch on or off.
    app(CloseRoom::class)->handle($room);
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('state.voice', null));
});

it('counts the voice as off when its server is not configured', function (): void {
    $named = [
        'elifba.rooms.enabled' => true,
        'elifba.rooms.voice.enabled' => true,
        'elifba.rooms.voice.key' => FakeVoiceServer::KEY,
        'elifba.rooms.voice.secret' => FakeVoiceServer::SECRET,
        'elifba.rooms.voice.url' => FakeVoiceServer::URL,
        'elifba.rooms.voice.api_url' => FakeVoiceServer::API_URL,
    ];

    Config::set($named);
    expect(RoomsArea::voice())->toBeTrue()
        ->and(RoomVoiceServer::configured())->toBeTrue();

    foreach (['elifba.rooms.voice.key', 'elifba.rooms.voice.secret', 'elifba.rooms.voice.url', 'elifba.rooms.voice.api_url'] as $key) {
        foreach ([null, '', '   '] as $value) {
            Config::set([...$named, $key => $value]);
            expect(RoomsArea::voice())->toBeFalse("{$key} = ".var_export($value, true));
        }
    }

    // The switch itself, and the area under it.
    Config::set([...$named, 'elifba.rooms.voice.enabled' => false]);
    expect(RoomsArea::voice())->toBeFalse()
        // The sweep still runs, to close what was left open.
        ->and(RoomVoiceServer::configured())->toBeTrue();
    Config::set([...$named, 'elifba.rooms.enabled' => false]);
    expect(RoomsArea::voice())->toBeFalse();

    // The voice server is named by its key, secret and API address alone.
    Config::set([...$named, 'elifba.rooms.voice.url' => null]);
    expect(RoomVoiceServer::configured())->toBeTrue();
    Config::set([...$named, 'elifba.rooms.voice.api_url' => '']);
    expect(RoomVoiceServer::configured())->toBeFalse();
});

it('reads the voice switch from the environment as a yes or a no', function (string $value, bool $enabled): void {
    // As RoomsSwitchTest reads ELIFBA_ROOMS: a .env that sets the key fills
    // $_SERVER and $_ENV before getenv(); set all three and put them back.
    $server = $_SERVER['ELIFBA_ROOMS_VOICE'] ?? null;
    $env = $_ENV['ELIFBA_ROOMS_VOICE'] ?? null;
    $process = getenv('ELIFBA_ROOMS_VOICE');

    putenv("ELIFBA_ROOMS_VOICE={$value}");
    $_SERVER['ELIFBA_ROOMS_VOICE'] = $value;
    $_ENV['ELIFBA_ROOMS_VOICE'] = $value;

    try {
        $config = require config_path('elifba.php');
    } finally {
        putenv($process === false ? 'ELIFBA_ROOMS_VOICE' : "ELIFBA_ROOMS_VOICE={$process}");

        if ($server === null) {
            unset($_SERVER['ELIFBA_ROOMS_VOICE']);
        } else {
            $_SERVER['ELIFBA_ROOMS_VOICE'] = $server;
        }

        if ($env === null) {
            unset($_ENV['ELIFBA_ROOMS_VOICE']);
        } else {
            $_ENV['ELIFBA_ROOMS_VOICE'] = $env;
        }
    }

    expect($config['rooms']['voice']['enabled'])->toBe($enabled)
        ->and($config['rooms']['voice']['ticket_seconds'])->toBe(60)
        ->and($config['rooms']['voice']['timeout_ms'])->toBe(1500)
        ->and($config['rooms']['voice']['max_rooms'])->toBe(5);
})->with([
    'true' => ['true', true],
    '1' => ['1', true],
    'false' => ['false', false],
    'off' => ['off', false],
    'empty' => ['', false],
]);

it('runs every room test without a voice server', function (): void {
    // What `Tests\TestCase` leaves for every test: the switch off, no server named.
    expect(Config::get('elifba.rooms.voice.enabled'))->toBeFalse()
        ->and(array_map(static fn (string $key): mixed => Config::get("elifba.rooms.voice.{$key}"), ['key', 'secret', 'url', 'api_url']))->toBe([null, null, null, null])
        ->and(RoomVoiceServer::configured())->toBeFalse();

    // A room whose rows say its voice is on goes through every cut without a request.
    Http::fake();
    Rooms::on();
    $room = Rooms::open();
    $row = Rooms::join($room);
    $other = Rooms::join($room);
    $room->forceFill(['voice_at' => now()])->save();
    app(LeaveRoom::class)->handle($room, $row);
    app(RemoveRoomPlayer::class)->handle($room, $other);
    app(CloseRoom::class)->handle($room);
    $this->artisan('rooms:voice-sweep');

    Http::assertNothingSent();
    expect(Room::query()->whereKey($room->id)->value('voice_epoch'))->toBe(2);

    // Nor is the sweep scheduled without a voice server: an instance without the profile calls none.
    $schedule = new Schedule;
    SweepRoomVoices::schedule($schedule);
    expect($schedule->events())->toBe([]);

    Config::set(['elifba.rooms.voice.key' => FakeVoiceServer::KEY, 'elifba.rooms.voice.secret' => FakeVoiceServer::SECRET, 'elifba.rooms.voice.api_url' => FakeVoiceServer::API_URL]);
    SweepRoomVoices::schedule($schedule);
    /** @var ScheduledEvent $sweep */
    $sweep = $schedule->events()[0];
    expect($schedule->events())->toHaveCount(1)
        ->and($sweep->command)->toContain('rooms:voice-sweep')
        ->and($sweep->repeatSeconds)->toBe(15);
});
