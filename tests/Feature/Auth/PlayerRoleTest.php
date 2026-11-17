<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Support\Rooms;

/*
 * The player role (docs/architecture.md §1 "Accounts and roles", §7
 * "Agreements", §12 "The area and the switch", ADR 019): taken like the
 * others, on `/role` or in Settings → «Rollar», with its own sentence; it
 * opens the player area, whose home is «Otaqlar». An account that opens a
 * page of the area without the role is sent to take it, «Oyunçu»
 * preselected, and comes back to the page (decision 6). The switch is on
 * in every test here; tests/Feature/Rooms/RoomsSwitchTest.php has it off.
 */

beforeEach(function (): void {
    Rooms::on();
});

it('grants the player role and stamps its agreement', function (): void {
    $this->travelTo('2026-11-17 19:40:00');
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)->get(route('role'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/choose-role')
            ->where('roles', ['teacher', 'parent', 'player'])
            ->where('chosen', null));

    $this->post(route('role.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('start'));

    $user->refresh();
    expect($user->roleList())->toBe([Role::Player])
        ->and($user->area)->toBe(Role::Player)
        ->and($user->player_agreed_at?->toDateTimeString())->toBe('2026-11-17 19:40:00')
        ->and($user->teacher_agreed_at)->toBeNull()
        ->and($user->parent_agreed_at)->toBeNull();

    $this->get(route('start'))->assertRedirect(route('rooms.index'));
    $this->get(route('rooms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/index')
            ->where('auth.roles', ['player'])
            ->where('auth.area', 'player')
            ->missing('auth.user.player_agreed_at'));
});

it('refuses the role without its sentence', function (string $state, string $page, string $store, array $input, string $field): void {
    $user = User::factory()->{$state}()->create();
    $roles = $user->roleList();

    $this->actingAs($user)
        ->from(route($page))
        ->post(route($store), $input)
        ->assertRedirect(route($page))
        ->assertSessionHasErrors($field);

    $user->refresh();
    expect($user->roleList())->toBe($roles)
        ->and($user->player_agreed_at)->toBeNull()
        ->and($user->hasRole(Role::Player))->toBeFalse();
})->with([
    'no role yet, no agreement' => ['withoutRole', 'role', 'role.store', ['role' => 'player'], 'agreement'],
    'no role yet, unticked' => ['withoutRole', 'role', 'role.store', ['role' => 'player', 'agreement' => '0'], 'agreement'],
    'a teacher, no agreement' => ['configure', 'roles.edit', 'roles.store', ['role' => 'player'], 'agreement'],
    'a parent, unticked' => ['parent', 'roles.edit', 'roles.store', ['role' => 'player', 'agreement' => '0'], 'agreement'],
]);

it('says the player sentence in both languages, apart from the others', function (): void {
    expect(__('roles.agreement.player', [], 'az'))->toBe('Otaqlarda öz adımla oynayıram; adım və nəticələrim olduğum otağın oyunçularına görünür.')
        ->and(__('roles.agreement.player', [], 'en'))->toBe('I play in rooms under my own name; my name and my results are shown to the players of the rooms I am in.');

    foreach (['az', 'en'] as $locale) {
        expect(__('roles.agreement.player', [], $locale))
            ->not->toBe(__('roles.agreement.teacher', [], $locale))
            ->not->toBe(__('roles.agreement.parent', [], $locale))
            ->not->toBe(__('auth.register.terms', [], $locale));
    }
});

it('adds the role to a teacher from the roles page', function (): void {
    $this->travelTo('2026-11-11 09:00:00');
    $teacher = User::factory()->create();
    $this->travelTo('2026-12-01 18:30:00');

    $this->actingAs($teacher)->get(route('roles.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/roles')
            ->where('held', ['teacher'])
            ->where('available', ['parent', 'player'])
            ->where('chosen', null));

    $this->post(route('roles.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rooms.index'));

    $teacher->refresh();
    expect($teacher->roleList())->toBe([Role::Teacher, Role::Player])
        ->and($teacher->player_agreed_at?->toDateTimeString())->toBe('2026-12-01 18:30:00')
        ->and($teacher->teacher_agreed_at?->toDateTimeString())->toBe('2026-11-11 09:00:00')
        ->and($teacher->parent_agreed_at)->toBeNull()
        ->and($teacher->area())->toBe(Role::Player);

    $this->get(route('rooms.index'))->assertOk();
    $this->get(route('classes.index'))->assertOk();
    $this->get(route('roles.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('held', ['teacher', 'player'])
        ->where('available', ['parent']));
});

it('lands a player on the rooms', function (): void {
    $this->actingAs(User::factory()->player()->create())
        ->get(route('start'))
        ->assertRedirect(route('rooms.index'));

    // With another role: the area the account is in.
    $both = User::factory()->teacherAndPlayer()->create();
    $this->actingAs($both)->get(route('start'))->assertRedirect(route('classes.index'));
    $this->put(route('area.update'), ['area' => 'player'])->assertRedirect(route('rooms.index'));
    $this->get(route('start'))->assertRedirect(route('rooms.index'));

    // The logo, the old /dashboard and the login lead there too.
    auth()->logout();
    $this->post(route('login.store'), ['email' => $both->email, 'password' => 'password'])
        ->assertRedirect(route('start', absolute: false));
    $this->get('/dashboard')->assertRedirect('/start');
});

it('adds the player role without giving it to any existing account', function (): void {
    $teacher = User::factory()->create();
    $parent = User::factory()->parent()->create();
    $both = User::factory()->teacherAndParent()->create();
    $none = User::factory()->withoutRole()->create();

    $migration = require database_path('migrations/2026_11_23_000001_add_player_role.php');
    $migration->down();

    expect(DB::table('roles')->where('name', 'player')->exists())->toBeFalse();

    $migration->up();

    expect(DB::table('roles')->orderBy('id')->get(['name', 'guard_name'])->map(static fn (object $row): array => (array) $row)->all())
        ->toBe([
            ['name' => 'teacher', 'guard_name' => 'web'],
            ['name' => 'parent', 'guard_name' => 'web'],
            ['name' => 'player', 'guard_name' => 'web'],
        ]);

    $player = DB::table('roles')->where('name', 'player')->value('id');
    expect(DB::table('model_has_roles')->where('role_id', $player)->count())->toBe(0)
        ->and(DB::table('users')->whereNotNull('player_agreed_at')->count())->toBe(0)
        ->and(DB::table('users')->where('area', 'player')->count())->toBe(0);

    foreach ([[$teacher, [Role::Teacher]], [$parent, [Role::Parent]], [$both, [Role::Teacher, Role::Parent]], [$none, []]] as [$user, $roles]) {
        expect($user->refresh()->roleList())->toBe($roles);
    }
});

it('sends an account without the role through the role and on to the rooms', function (string $state, string $rolesPage): void {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('rooms.index'))
        ->assertRedirect(route($rolesPage, ['role' => 'player']));

    // «Oyunçu» is preselected; taking it still needs its sentence.
    $this->get(route($rolesPage, ['role' => 'player']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chosen', 'player'));

    $this->post(route($rolesPage === 'role' ? 'role.store' : 'roles.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rooms.index'));

    expect($user->refresh()->hasRole(Role::Player))->toBeTrue()
        ->and(session('url.intended'))->toBeNull();
    $this->get(route('rooms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('rooms/index'));
})->with([
    'no role yet' => ['withoutRole', 'role'],
    'a teacher' => ['configure', 'roles.edit'],
    'a parent' => ['parent', 'roles.edit'],
    'a teacher and a parent' => ['teacherAndParent', 'roles.edit'],
]);

it('sends an invited account through the role and on to the room', function (string $state, string $rolesPage): void {
    $room = Rooms::open(User::factory()->player()->create(['name' => 'Rəşad Quliyev']));
    $code = (string) $room->code;
    $user = User::factory()->{$state}()->create(['name' => 'Aygün Məmmədova']);

    // The code from a friend, opened before signing in.
    $this->get(route('rooms.join', $code))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('rooms.join', $code));

    // No player role yet: take it, «Oyunçu» preselected, and come back to the code.
    $this->get(route('rooms.join', $code))->assertRedirect(route($rolesPage, ['role' => 'player']));
    $this->get(route($rolesPage, ['role' => 'player']))->assertInertia(fn (Assert $page) => $page->where('chosen', 'player'));
    $this->post(route($rolesPage === 'role' ? 'role.store' : 'roles.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rooms.join', $code));

    $this->get(route('rooms.join', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rooms/join')->where('code', $code)->where('name', 'Aygün Məmmədova'));
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $room));

    $this->get(route('rooms.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('state.players.0.name', 'Rəşad Quliyev')
            ->where('state.players.1.name', 'Aygün Məmmədova'));
    expect($user->refresh()->hasRole(Role::Player))->toBeTrue()
        ->and(session('url.intended'))->toBeNull();
})->with([
    'no role yet' => ['withoutRole', 'role'],
    'a teacher' => ['configure', 'roles.edit'],
    'a parent' => ['parent', 'roles.edit'],
]);

it('brings a visitor through the login and the role back to the rooms', function (): void {
    $teacher = User::factory()->create();

    $this->get(route('rooms.index'))->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
        ->assertRedirect(route('rooms.index'));

    $this->get(route('rooms.index'))->assertRedirect(route('roles.edit', ['role' => 'player']));
    $this->post(route('roles.store'), ['role' => 'player', 'agreement' => '1'])->assertRedirect(route('rooms.index'));
    $this->get(route('rooms.index'))->assertOk();
});

it('brings a new account through the registration, the role and the e-mail confirmation back to the rooms', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    Notification::fake();

    $this->get(route('rooms.index'))->assertRedirect(route('login'));

    $this->post(route('register.store'), [
        'name' => 'Rəşad Quliyev',
        'email' => 'resad@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertRedirect(route('rooms.index'));

    $this->get(route('rooms.index'))->assertRedirect(route('role', ['role' => 'player']));
    $this->post(route('role.store'), ['role' => 'player', 'agreement' => '1'])->assertRedirect(route('rooms.index'));

    // The address waits for the confirmation, too.
    $this->get(route('rooms.index'))->assertRedirect(route('verification.notice'));

    $user = User::query()->sole();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]))
        ->assertRedirect(route('rooms.index'));

    $this->get(route('rooms.index'))->assertOk();
    expect($user->refresh()->roleList())->toBe([Role::Player]);
});

it('goes on to no address but a page of the player area of this app', function (string $intended): void {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)
        ->withSession(['url.intended' => $intended])
        ->post(route('role.store'), ['role' => 'player', 'agreement' => '1'])
        ->assertRedirect(route('start'));

    // Not taken: the session keeps it as it was.
    expect(session('url.intended'))->toBe($intended);
})->with([
    'another site' => 'https://evil.example/rooms',
    'another site that starts like this one' => fn (): string => url('/').'.evil.example/rooms',
    'a page of the teacher area' => fn (): string => url('/classes'),
    'a page outside any area' => fn (): string => url('/privacy'),
    'no page at all' => fn (): string => url('/rooms-and-more'),
    'a path, not an address' => '/rooms',
]);

it('follows a room address only with the player role', function (): void {
    $user = User::factory()->withoutRole()->create();

    // Choosing another role leads home and leaves the address for later.
    $this->actingAs($user)
        ->withSession(['url.intended' => url('/rooms')])
        ->post(route('role.store'), ['role' => 'teacher', 'agreement' => '1'])
        ->assertRedirect(route('start'));

    expect(session('url.intended'))->toBe(url('/rooms'));

    $this->post(route('roles.store'), ['role' => 'player', 'agreement' => '1'])->assertRedirect(url('/rooms'));
    expect(session('url.intended'))->toBeNull();
});

it('preselects only a role the page offers', function (string $state, string $page, string|array $role): void {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route($page, ['role' => $role]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chosen', null));
})->with([
    'an unknown role' => ['withoutRole', 'role', 'admin'],
    'a role it holds' => ['player', 'roles.edit', 'player'],
    'nothing' => ['configure', 'roles.edit', ''],
    'a list' => ['withoutRole', 'role', ['player']],
]);

it('walks a player through every account and shared page it can reach', function (): void {
    $player = User::factory()->player()->create();
    $this->actingAs($player)->withSession(['auth.password_confirmed_at' => time()]);

    $inArea = static fn (string $component): Closure => static fn (Assert $page): Assert => $page
        ->component($component)
        ->where('auth.roles', ['player'])
        ->where('auth.area', 'player');

    $this->get(route('rooms.index'))->assertOk()->assertInertia($inArea('rooms/index'));
    $this->get(route('profile.edit'))->assertOk()->assertInertia($inArea('settings/profile'));
    $this->get(route('security.edit'))->assertOk()->assertInertia($inArea('settings/security'));
    $this->get(route('appearance.edit'))->assertOk()->assertInertia($inArea('settings/appearance'));
    $this->get(route('roles.edit'))->assertOk()->assertInertia(fn (Assert $page) => $inArea('settings/roles')($page)
        ->where('held', ['player'])
        ->where('available', ['teacher', 'parent']));
    $this->get(route('role'))->assertRedirect(route('start'));
    $this->get('/settings')->assertRedirect('/settings/profile');

    // The public pages still open, signed in.
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.area', 'player'));
    $this->get(route('privacy'))->assertOk();
    $this->get(route('credits'))->assertOk();

    // The boards' settings, the board and the remote are not the player's.
    $this->get(route('play-settings.edit'))->assertRedirect(route('start'));
    $this->get(route('pult'))->assertRedirect(route('start'));
    $this->putJson(route('play-settings.update'), ['phone_remote' => false])->assertForbidden();
    expect($player->refresh()->phone_remote)->toBeTrue();

    // Nor the teacher's or the parent's area.
    $this->get(route('classes.index'))->assertRedirect(route('start'));
    $this->get(route('children.index'))->assertRedirect(route('start'));
    $this->get(route('words.index'))->assertRedirect(route('start'));

    // There is no other area to switch to.
    $this->put(route('area.update'), ['area' => 'teacher'])->assertSessionHasErrors('area');
    expect($player->refresh()->area)->toBe(Role::Player);
});

it('switches between two and three areas', function (): void {
    $two = User::factory()->teacherAndPlayer()->create();

    $this->actingAs($two)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.roles', ['teacher', 'player'])
        ->where('auth.area', 'teacher'));
    $this->put(route('area.update'), ['area' => 'player'])->assertRedirect(route('rooms.index'));
    $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('auth.area', 'player'));
    // A page inside an area shows that area, whatever the account's is.
    $this->get(route('classes.index'))->assertInertia(fn (Assert $page) => $page->where('auth.area', 'teacher'));
    // A board's page is never in the player area (ADR 019 decisions 5, 8).
    $this->get(route('pult'))->assertInertia(fn (Assert $page) => $page->where('auth.area', 'teacher'));
    $this->get(route('play-settings.edit'))->assertInertia(fn (Assert $page) => $page->where('auth.area', 'teacher'));
    $this->put(route('area.update'), ['area' => 'parent'])->assertSessionHasErrors('area');
    $this->put(route('area.update'), ['area' => 'teacher'])->assertRedirect(route('classes.index'));

    $three = User::factory()->teacherAndParent()->create(['player_agreed_at' => now(), 'area' => Role::Parent]);
    $this->actingAs($three)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.roles', ['teacher', 'parent', 'player'])
        ->where('auth.area', 'parent'));

    foreach (['player' => 'rooms.index', 'teacher' => 'classes.index', 'parent' => 'children.index'] as $area => $home) {
        $this->put(route('area.update'), ['area' => $area])->assertRedirect(route($home));
        $this->get(route('start'))->assertRedirect(route($home));
        $this->get(route('rooms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.area', 'player'));
        $this->get(route('children.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.area', 'parent'));
        $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.area', $area));
        $this->get(route('pult'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.area', $area === 'player' ? 'teacher' : $area));
    }

    expect($three->refresh()->roleList())->toBe([Role::Teacher, Role::Parent, Role::Player]);
});
