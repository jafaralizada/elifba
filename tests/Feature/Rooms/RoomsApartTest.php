<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Family\Models\Child;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeVoiceServer;
use Tests\Support\Games;
use Tests\Support\GuestBoards;
use Tests\Support\Rooms;

/*
 * Rooms stay apart from the children's side (docs/architecture.md §12 "The
 * area and the switch", "Guard tests", ADR 019 decision 5, ADR 023 decision
 * 1), with the switch on: no class, child, ranking, words, setup, board,
 * remote, pult or guest board page hears of a room; no room route takes a
 * class, a student, a child, a game or a word; and the domains keep their
 * order — `Rooms` last, using only `Language`, `Content` and `DenseRank`,
 * and used by none; and a whole room game writes nothing of a class or a
 * child's game.
 */

const DOMAIN_ORDER = ['Language', 'Content', 'Classroom', 'Family', 'Play', 'Rankings', 'Rooms'];

/**
 * What `Rooms` may name of the app besides itself.
 */
const ROOMS_MAY_USE = [
    'App\\Domain\\Language\\',
    'App\\Domain\\Content\\',
    'App\\Domain\\Rankings\\Support\\DenseRank',
    'App\\Models\\User',
    'App\\Enums\\Role',
];

/**
 * Every `App\…` class a PHP file names, in code or in a docblock.
 *
 * @return list<string>
 */
function appNamesIn(string $path): array
{
    preg_match_all('/\\\\?(App\\\\[A-Za-z0-9_\\\\]+)/', (string) file_get_contents($path), $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    return array_values(array_map(
        static fn ($file): string => $file->getPathname(),
        array_filter(File::allFiles($directory), static fn ($file): bool => $file->getExtension() === 'php'),
    ));
}

it('sends nothing about rooms to a class, child, ranking, words, setup, board, remote, pult or guest board page', function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $account = User::factory()->teacherAndParent()->create(['player_agreed_at' => now()]);
    $class = SchoolClass::factory()->for($account, 'teacher')->create();
    $student = Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    $child = Child::factory()->for($account, 'parent')->create(['display_name' => 'Firuzə']);
    $game = Games::start($class, seed: 1);
    $home = Games::start($child, seed: 2);
    $board = GuestBoards::showing($game);
    $room = Rooms::open($account);
    $member = Rooms::join($room, User::factory()->player()->create(['name' => 'Aygün Məmmədova']));
    $secrets = [$room->id, $member->id, (string) $room->code, 'Aygün', '/rooms', '/otaq', 'room.changed', 'private-room'];
    $silent = static function (string $wire, string $where) use ($secrets): void {
        foreach ($secrets as $secret) {
            expect($wire)->not->toContain($secret, $where);
        }
    };

    $this->actingAs($account);

    foreach ([
        route('classes.index'),
        route('classes.show', $class),
        route('classes.rankings', $class),
        route('classes.podium', $class),
        route('words.index'),
        route('classes.games.create', $class),
        route('children.index'),
        route('children.show', $child),
        route('children.games.create', $child),
        route('games.board', $game),
        route('games.remote', $game),
        route('games.board', $home),
        route('pult', ['list' => 1]),
        route('play-settings.edit'),
        route('guest-boards.game.edit', $board['board']),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($url, $silent): void {
                $props = $page->toArray()['props'];
                // The interface's translations name every page; the role list
                // names «player» for the user menu's switch, and nothing else does.
                unset($props['translations'], $props['auth']['roles']);

                $silent((string) json_encode($props, JSON_UNESCAPED_UNICODE), $url);
            });
    }

    // The board's own requests, by the session and by the guest board's bearer.
    $silent((string) $this->getJson(route('games.state', $game))->assertOk()->getContent(), 'games.state');
    $this->app['auth']->guard('web')->forgetUser();
    $bearer = GuestBoards::bearer($board['token']);
    $silent((string) $this->getJson(route('games.state', $game), $bearer)->assertOk()->getContent(), 'games.state (bearer)');
    $silent((string) $this->getJson(route('guest-boards.show', $board['board']), $bearer)->assertOk()->getContent(), 'guest-boards.show');
    $this->get(route('lovhe'))->assertOk()->assertInertia(function (Assert $page) use ($silent): void {
        $props = $page->toArray()['props'];
        unset($props['translations']);
        $silent((string) json_encode($props, JSON_UNESCAPED_UNICODE), 'lovhe');
    });

    expect($student->refresh()->display_name)->toBe('Aysel');
});

// Live voice (C3, ADR 031 decision 33): a class game, a home game, a board,
// a student and a child never get voice, and nothing of a room's voice
// reaches their pages — with the voice switch on, a room talking and the
// account's own agreement stamped.
it('sends nothing about voice to a class, child, ranking, words, setup, board, remote, pult or guest board page', function (): void {
    $voice = Rooms::voiceOn();
    $this->travelTo('2026-11-24 10:00:00');
    $account = User::factory()->teacherAndParent()->create(['player_agreed_at' => now(), 'voice_agreed_at' => now()]);
    $class = SchoolClass::factory()->for($account, 'teacher')->create();
    $student = Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    $child = Child::factory()->for($account, 'parent')->create(['display_name' => 'Firuzə']);
    $game = Games::start($class, seed: 1);
    $home = Games::start($child, seed: 2);
    $board = GuestBoards::showing($game);
    $room = Rooms::open($account);
    $member = Rooms::join($room, User::factory()->player()->create(['name' => 'Aygün Məmmədova']));
    $this->actingAs($account)->putJson(route('rooms.voice.update', $room), ['on' => true])->assertNoContent();
    $this->actingAs($account)->putJson(route('rooms.members.voice.update', ['room' => $room, 'member' => $member]), ['standing' => 'muted'])->assertNoContent();
    $this->actingAs($account)->postJson(route('rooms.voice.tickets.store', $room))->assertOk();
    $calls = count($voice->sent);
    $secrets = [
        $room->id, $member->id, $room->refresh()->voiceRoom(), 'Aygün',
        'voice_at', 'voice_epoch', 'voice_agreed_at', 'voice_admitted_at', 'voice_muted_at',
        'voice/tickets', '"agreed"', 'canPublish', 'livekit', 'Canlı səs',
        FakeVoiceServer::URL, FakeVoiceServer::API_URL, FakeVoiceServer::KEY, FakeVoiceServer::SECRET,
    ];
    $silent = static function (string $wire, string $where) use ($secrets): void {
        foreach ($secrets as $secret) {
            expect($wire)->not->toContain($secret, $where);
        }
    };

    $this->actingAs($account);

    foreach ([
        route('classes.index'),
        route('classes.show', $class),
        route('classes.rankings', $class),
        route('classes.podium', $class),
        route('words.index'),
        route('classes.games.create', $class),
        route('children.index'),
        route('children.show', $child),
        route('children.games.create', $child),
        route('games.board', $game),
        route('games.remote', $game),
        route('games.board', $home),
        route('pult', ['list' => 1]),
        route('play-settings.edit'),
        route('guest-boards.game.edit', $board['board']),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($url, $silent): void {
                $props = $page->toArray()['props'];
                unset($props['translations']);

                $silent((string) json_encode($props, JSON_UNESCAPED_UNICODE), $url);
            });
    }

    $silent((string) $this->getJson(route('games.state', $game))->assertOk()->getContent(), 'games.state');
    $this->app['auth']->guard('web')->forgetUser();
    $bearer = GuestBoards::bearer($board['token']);
    $silent((string) $this->getJson(route('games.state', $game), $bearer)->assertOk()->getContent(), 'games.state (bearer)');
    $silent((string) $this->getJson(route('guest-boards.show', $board['board']), $bearer)->assertOk()->getContent(), 'guest-boards.show');
    // A guest board's bearer gets no ticket either: it is a visitor to every room route.
    $this->postJson(route('rooms.voice.tickets.store', $room), ['agree' => true], $bearer)->assertUnauthorized();

    expect($voice->sent)->toHaveCount($calls)
        ->and($student->refresh()->display_name)->toBe('Aysel');
});

it('leaves the game tables untouched by a whole room game', function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $account = User::factory()->teacherAndParent()->create(['player_agreed_at' => now()]);
    $class = SchoolClass::factory()->for($account, 'teacher')->create();
    Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    $child = Child::factory()->for($account, 'parent')->create(['display_name' => 'Firuzə']);
    Games::start($class, seed: 1);
    Games::start($child, seed: 2);
    GuestBoards::showing(Games::start($class, seed: 3));
    $tables = ['game_sessions', 'game_rounds', 'game_answers', 'guest_boards', 'school_classes', 'students', 'children', 'setup_choices'];
    $children = static fn (): array => array_combine($tables, array_map(
        static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        $tables,
    ));
    $before = $children();

    // The account hosts a room and plays a whole game in it over HTTP with a friend.
    $room = Rooms::open($account);
    $friend = User::factory()->player()->create();
    Rooms::join($room, $friend);
    $this->actingAs($account)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'recall_order', 'difficulty' => 2, 'rounds' => 5])->assertNoContent();
    $this->actingAs($account)->postJson(route('rooms.games.store', $room))->assertNoContent();
    $game = RoomGame::query()->sole();

    foreach (range(0, 4) as $index) {
        $round = Rooms::round($game, $index);
        $this->travelTo(Rooms::at($game, RoomPhase::Answer, $index));
        $this->actingAs($account)->getJson(route('rooms.state', $room))->assertJsonPath('round.phase', 'answer');
        $this->actingAs($friend)->getJson(route('rooms.state', $room))->assertJsonPath('round.phase', 'answer');
        $this->actingAs($account)->postJson(route('rooms.answers.store', $room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');
        $this->actingAs($friend)->postJson(route('rooms.answers.store', $room), Rooms::answer($round, Rooms::wrong($round)))->assertJsonPath('status', 'accepted');
        $this->actingAs($friend)->getJson(route('rooms.state', $room))->assertJsonPath('round.phase', 'reveal');
    }

    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $this->actingAs($account)->getJson(route('rooms.state', $room))->assertJsonPath('game.phase', 'over');
    $this->post(route('rooms.close', $room))->assertRedirect();

    expect(RoomAnswer::query()->count())->toBe(10)
        ->and($children())->toBe($before);
});

it('takes no class, student, child, game or word in any room route', function (): void {
    $children = ['class', 'student', 'child', 'game', 'word', 'player', 'board'];
    $rooms = Rooms::routes();

    expect($rooms)->not->toBeEmpty();

    foreach ($rooms as $key => $route) {
        expect(array_intersect($route->parameterNames(), $children))->toBe([], $key)
            ->and($route->uri())->toMatch('#^(rooms|oyun|otaq)(/|$)#', $key);
    }

    // …and no other route takes a room or one of its players.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (array_intersect($route->parameterNames(), ['room', 'member']) !== []) {
            expect(in_array('rooms.enabled', $route->gatherMiddleware(), true))->toBeTrue($route->uri())
                ->and($route->gatherMiddleware())->toContain(Role::middleware(Role::Player));
        }
    }
});

it('keeps the domains apart', function (): void {
    $wrong = [];

    foreach (DOMAIN_ORDER as $position => $domain) {
        $later = array_slice(DOMAIN_ORDER, $position + 1);

        foreach (phpFilesUnder(app_path('Domain/'.$domain)) as $path) {
            $file = str_replace(base_path().'/', '', $path);

            foreach (appNamesIn($path) as $name) {
                if (! str_starts_with($name, 'App\\Domain\\')) {
                    // Rooms names nothing of the app outside its list.
                    if ($domain === 'Rooms' && ! array_any(ROOMS_MAY_USE, static fn (string $allowed): bool => str_starts_with($name, $allowed))) {
                        $wrong[$file][] = $name;
                    }

                    continue;
                }

                $used = explode('\\', $name)[2] ?? '';

                // A domain uses the ones before it, never the ones after.
                if (in_array($used, $later, true)) {
                    $wrong[$file][] = $name;
                }

                // Rooms, stricter: Language, Content and DenseRank only.
                if ($domain === 'Rooms' && $used !== 'Rooms' && ! array_any(ROOMS_MAY_USE, static fn (string $allowed): bool => str_starts_with($name, $allowed))) {
                    $wrong[$file][] = $name;
                }
            }
        }
    }

    // Nothing of the app uses Rooms but its own wiring: its controllers.
    foreach (phpFilesUnder(app_path()) as $path) {
        $file = str_replace(base_path().'/', '', $path);

        if (str_starts_with($file, 'app/Domain/Rooms/') || str_starts_with($file, 'app/Http/Controllers/Rooms/')) {
            continue;
        }

        foreach (appNamesIn($path) as $name) {
            if (str_starts_with($name, 'App\\Domain\\Rooms') || str_starts_with($name, 'App\\Http\\Controllers\\Rooms')) {
                $wrong[$file][] = $name;
            }
        }
    }

    expect($wrong)->toBe([], 'docs/architecture.md §1: Language → Content → Classroom → Family → Play → Rankings → Rooms; Rooms uses only Language, Content and DenseRank.')
        ->and(File::isDirectory(app_path('Domain/Rooms')))->toBeTrue();
});

it('keeps the room pages apart from the children\'s on the page side', function (): void {
    $wrong = [];
    $js = resource_path('js');

    foreach (File::allFiles($js) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $path = str_replace($js.'/', '', $file->getPathname());
        preg_match_all("/from '([^']+)'/", $file->getContents(), $matches);
        $inRooms = str_starts_with($path, 'rooms/') || str_starts_with($path, 'pages/rooms/') || str_starts_with($path, 'components/rooms/');

        foreach ($matches[1] as $import) {
            $roomModule = preg_match('#^@/(rooms|components/rooms)(/|$)#', $import) === 1;
            $childrenModule = preg_match('#^@/(game|components/(games|children|classes|students|rankings|share|words))(/|$)#', $import) === 1;

            if (($inRooms && $childrenModule) || (! $inRooms && $roomModule)) {
                $wrong[$path][] = $import;
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('takes the taunts\' images from the room\'s own list, never from the children\'s catalog', function (): void {
    $wrong = [];
    // What the children's games are drawn from: packs, their words and pictures, levels, prompts, avatars.
    $catalog = ['Content\\Data\\PackData', 'Content\\Data\\WordData', 'Content\\Data\\LevelData', 'Content\\Data\\PromptTemplateData', 'Content\\Support\\Avatars', 'Content\\Support\\TeacherPacks', 'Content\\Enums\\Mechanic'];

    foreach ([...phpFilesUnder(app_path('Domain/Rooms')), ...phpFilesUnder(app_path('Http/Controllers/Rooms'))] as $path) {
        $file = str_replace(base_path().'/', '', $path);
        $source = (string) file_get_contents($path);

        foreach (appNamesIn($path) as $name) {
            if (array_any($catalog, static fn (string $part): bool => str_ends_with($name, $part))) {
                $wrong[$file][] = $name;
            }
        }

        if (preg_match('/->(packs?|levels?|prompts?)\(/', $source) === 1) {
            $wrong[$file][] = 'a pack, a level or a prompt of the children\'s content';
        }
    }

    // A room page gets every emoji by the URL of its prop, never by a path of its own.
    foreach (['rooms', 'pages/rooms', 'components/rooms'] as $folder) {
        foreach (File::allFiles(resource_path("js/{$folder}")) as $file) {
            if (str_contains($file->getContents(), 'fluent/')) {
                $wrong[str_replace(base_path().'/', '', $file->getPathname())][] = 'fluent/';
            }
        }
    }

    // And the list names only the Fluent set.
    foreach (app(ContentRepository::class)->roomTaunts() as $taunt) {
        expect($taunt->image)->toStartWith('fluent/');
    }

    expect($wrong)->toBe([]);
});
