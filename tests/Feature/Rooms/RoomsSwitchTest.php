<?php

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Enums\Role;
use App\Http\Middleware\EnsureRoomsAreEnabled;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * The switch of the rooms area (docs/architecture.md §12 "The area and the
 * switch", "Guard tests", ADR 019 decision 4). `elifba.rooms.enabled` is
 * off unless the instance sets `ELIFBA_ROOMS`, and `Tests\TestCase` leaves
 * it off. While it is off every room route is a 404 to everyone, the
 * player role is neither offered nor granted, and an account that holds it
 * is seen as if it did not: every page, `/start` and the area switch
 * behave as before the area existed. The routes are those that carry
 * `rooms.enabled`, so the tests grow with them.
 */

/**
 * The values the room routes take: a room `$host` hosts, with `$member`
 * in it, and its code — so that, the switch aside, the routes would let
 * them through.
 *
 * @return array<string, int|string>
 */
function roomsSwitchValues(User $host, User $member): array
{
    $room = Room::factory()->for($host, 'host')->create();
    RoomPlayer::factory()->for($room)->for($host)->create();
    $row = RoomPlayer::factory()->for($room)->for($member)->create();

    return ['room' => $room->id, 'member' => $row->id, 'code' => (string) $room->code];
}

/**
 * What a refused request could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function roomsSwitchSnapshot(): array
{
    $rows = static fn (string $table, string $order): array => DB::table($table)->orderBy($order)->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return [
        'users' => $rows('users', 'id'),
        'model_has_roles' => $rows('model_has_roles', 'model_id'),
        'roles' => $rows('roles', 'id'),
        'rooms' => $rows('rooms', 'id'),
        'room_players' => $rows('room_players', 'id'),
        'room_games' => $rows('room_games', 'id'),
        'room_rounds' => $rows('room_rounds', 'id'),
        'room_answers' => $rows('room_answers', 'id'),
        'room_taunts' => $rows('room_taunts', 'id'),
    ];
}

it('answers 404 on every room route while the switch is off', function (): void {
    $routes = Rooms::routes();
    $accounts = [
        'a player' => User::factory()->player()->create(),
        'a teacher who plays' => User::factory()->teacherAndPlayer()->create(['area' => Role::Player]),
        'a teacher' => User::factory()->create(),
        'no role yet' => User::factory()->withoutRole()->create(),
        'unconfirmed' => User::factory()->player()->unverified()->create(),
    ];
    $values = roomsSwitchValues($accounts['a player'], $accounts['a teacher who plays']);
    $before = roomsSwitchSnapshot();
    $checked = 0;

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $key => $route) {
        $uri = Rooms::uri($route, $values);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            // A visitor first: not even the login says that the route exists.
            // Forgotten, not logged out: a logout would rotate the last account's remember token.
            $this->app['auth']->guard('web')->forgetUser();
            expect($this->call($method, $uri)->status())->toBe(404, "{$method} {$key}, a visitor")
                ->and($this->json($method, $uri)->status())->toBe(404, "{$method} {$key}, a visitor (JSON)");

            foreach ($accounts as $who => $account) {
                expect($this->actingAs($account)->call($method, $uri)->status())->toBe(404, "{$method} {$key}, {$who}")
                    ->and($this->actingAs($account)->json($method, $uri)->status())->toBe(404, "{$method} {$key}, {$who} (JSON)");
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(count($accounts))
        ->and(roomsSwitchSnapshot())->toBe($before)
        ->and(session('url.intended'))->toBeNull();

    // The switch, and nothing else, closed them.
    Rooms::on();
    auth()->logout();
    $this->get(route('rooms.index'))->assertRedirect(route('login'));
    $this->actingAs($accounts['a player'])->get(route('rooms.index'))->assertOk();
    $this->get(route('rooms.show', $values['room']))->assertOk();
    $this->getJson(route('rooms.state', $values['room']))->assertOk();
});

it('closes the live voice\'s routes with the area, its own switch on', function (): void {
    // The voice's switch on and its server named, the area off (C3, ADR 031).
    $voice = Rooms::voiceOn();
    Config::set('elifba.rooms.enabled', false);
    $host = User::factory()->player()->create();
    $values = roomsSwitchValues($host, User::factory()->player()->create());
    Room::query()->whereKey($values['room'])->update(['voice_at' => now()]);
    $before = roomsSwitchSnapshot();
    $checked = 0;

    foreach (Rooms::routes() as $key => $route) {
        if (! in_array('rooms.voice', $route->gatherMiddleware(), true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $uri = Rooms::uri($route, $values);
            $this->app['auth']->guard('web')->forgetUser();
            expect($this->json($method, $uri, ['on' => true, 'standing' => 'muted', 'agree' => true])->status())->toBe(404, "{$method} {$key}, a visitor")
                ->and($this->actingAs($host)->json($method, $uri, ['on' => true, 'standing' => 'muted', 'agree' => true])->status())->toBe(404, "{$method} {$key}, the host");
            $checked++;
        }
    }

    expect($checked)->toBe(3)
        ->and(roomsSwitchSnapshot())->toBe($before)
        ->and($voice->sent)->toBe([]);
});

it('runs the switch before anything else of a room route', function (): void {
    // The HTTP kernel gives the router the `web` group and the priority list.
    app(Kernel::class);
    $router = app('router');

    foreach (Rooms::routes() as $key => $route) {
        $middleware = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

        expect($middleware[0] ?? null)->toBe(EnsureRoomsAreEnabled::class, $key);
    }
});

it('reads the switch from the environment as a yes or a no', function (string $value, bool $enabled): void {
    // env() reads $_SERVER and $_ENV before getenv(), and a .env that sets
    // ELIFBA_ROOMS (CI's copy of .env.example does) fills both: set all
    // three, and put back what was there.
    $server = $_SERVER['ELIFBA_ROOMS'] ?? null;
    $env = $_ENV['ELIFBA_ROOMS'] ?? null;
    $process = getenv('ELIFBA_ROOMS');

    putenv("ELIFBA_ROOMS={$value}");
    $_SERVER['ELIFBA_ROOMS'] = $value;
    $_ENV['ELIFBA_ROOMS'] = $value;

    try {
        $config = require config_path('elifba.php');
    } finally {
        putenv($process === false ? 'ELIFBA_ROOMS' : "ELIFBA_ROOMS={$process}");

        if ($server === null) {
            unset($_SERVER['ELIFBA_ROOMS']);
        } else {
            $_SERVER['ELIFBA_ROOMS'] = $server;
        }

        if ($env === null) {
            unset($_ENV['ELIFBA_ROOMS']);
        } else {
            $_ENV['ELIFBA_ROOMS'] = $env;
        }
    }

    expect($config['rooms']['enabled'])->toBe($enabled);
})->with([
    'true' => ['true', true],
    '1' => ['1', true],
    'yes' => ['yes', true],
    'false' => ['false', false],
    'off' => ['off', false],
    'no' => ['no', false],
    '0' => ['0', false],
    'empty' => ['', false],
]);

it('offers and grants no player role while the switch is off', function (): void {
    $none = User::factory()->withoutRole()->create();
    $teacher = User::factory()->create();
    $before = roomsSwitchSnapshot();

    // Not offered: no «Oyunçu» card, not even when a link asks for it.
    $this->actingAs($none)->get(route('role', ['role' => 'player']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/choose-role')
            ->where('roles', ['teacher', 'parent'])
            ->where('chosen', null));
    $this->actingAs($teacher)->get(route('roles.edit', ['role' => 'player']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('held', ['teacher'])
            ->where('available', ['parent'])
            ->where('chosen', null));

    // Not granted, by either page, with its sentence or without.
    $this->actingAs($none)->from(route('role'))
        ->post(route('role.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertRedirect(route('role'))
        ->assertSessionHasErrors(['role' => __('roles.errors.role')]);
    $this->actingAs($teacher)->from(route('roles.edit'))
        ->post(route('roles.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertRedirect(route('roles.edit'))
        ->assertSessionHasErrors('role');

    expect(roomsSwitchSnapshot())->toBe($before)
        ->and($none->refresh()->player_agreed_at)->toBeNull()
        ->and($teacher->refresh()->player_agreed_at)->toBeNull();
});

it('shares no player role or area with any page while the switch is off', function (): void {
    $sees = static fn (array $roles, ?string $area): Closure => static fn (Assert $page): Assert => $page
        ->where('auth.roles', $roles)
        ->where('auth.area', $area)
        ->missing('auth.user.player_agreed_at');

    // A teacher who plays, last in the player area: a teacher, as before.
    $two = User::factory()->teacherAndPlayer()->create(['area' => Role::Player]);
    $this->actingAs($two);
    $this->get(route('start'))->assertRedirect(route('classes.index'));
    $this->get(route('classes.index'))->assertOk()->assertInertia($sees(['teacher'], 'teacher'));
    $this->get(route('profile.edit'))->assertOk()->assertInertia($sees(['teacher'], 'teacher'));
    $this->get(route('pult'))->assertOk()->assertInertia($sees(['teacher'], 'teacher'));
    $this->get(route('home'))->assertOk()->assertInertia($sees(['teacher'], 'teacher'));
    $this->get(route('roles.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('held', ['teacher'])
        ->where('available', ['parent']));
    $this->put(route('area.update'), ['area' => 'player'])->assertSessionHasErrors('area');
    expect($two->refresh()->area)->toBe(Role::Player);

    // With all three: the two of before, and the switch between them.
    $three = User::factory()->teacherAndParent()->create(['player_agreed_at' => now(), 'area' => Role::Player]);
    $this->actingAs($three);
    $this->get(route('profile.edit'))->assertInertia($sees(['teacher', 'parent'], 'teacher'));
    $this->put(route('area.update'), ['area' => 'parent'])->assertRedirect(route('children.index'));
    $this->get(route('profile.edit'))->assertInertia($sees(['teacher', 'parent'], 'parent'));
    $this->put(route('area.update'), ['area' => 'player'])->assertSessionHasErrors('area');

    // A player and nothing else: an account with no role, which may choose one.
    $player = User::factory()->player()->create();
    $this->actingAs($player);
    $this->get(route('start'))->assertRedirect(route('role'));
    $this->get(route('classes.index'))->assertRedirect(route('role'));
    $this->get(route('appearance.edit'))->assertRedirect(route('role'));
    $this->getJson(route('classes.index'))->assertStatus(409);
    $this->get(route('profile.edit'))->assertOk()->assertInertia($sees([], null));
    $this->get(route('role'))->assertOk()->assertInertia(fn (Assert $page) => $sees([], null)($page)
        ->component('auth/choose-role')
        ->where('roles', ['teacher', 'parent']));

    $this->post(route('role.store'), ['role' => 'parent', 'agreement' => '1'])->assertRedirect(route('start'));
    $this->get(route('start'))->assertRedirect(route('children.index'));
    $this->get(route('children.index'))->assertOk()->assertInertia($sees(['parent'], 'parent'));

    // It keeps the role it took: the area returns with the switch.
    expect($player->refresh()->hasRole(Role::Player))->toBeTrue();
    Rooms::on();
    $this->get(route('children.index'))->assertInertia($sees(['parent', 'player'], 'parent'));
});

it('reads the switch only through RoomsArea', function (): void {
    $readers = [];

    foreach ([app_path(), base_path('routes'), base_path('bootstrap'), database_path()] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'bootstrap/cache')) {
                continue;
            }

            if (preg_match('/elifba\.rooms\.enabled|ELIFBA_ROOMS/', $file->getContents()) === 1) {
                $readers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($readers)->toBe(['app/Support/RoomsArea.php']);
});

it('refuses the room channel while the switch is off', function (): void {
    $host = User::factory()->player()->create();
    $values = roomsSwitchValues($host, User::factory()->teacherAndPlayer()->create());
    $join = fn () => $this->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-room.'.$values['room'],
    ], ['Accept' => 'application/json']);

    $this->actingAs($host);
    $join()->assertForbidden();

    // The switch, and nothing else, closed it.
    Rooms::on();
    $join()->assertOk()->assertJsonStructure(['auth']);
});
