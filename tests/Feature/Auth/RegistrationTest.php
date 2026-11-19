<?php

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Enums\Role;
use App\Models\User;
use App\Support\RoomInvitation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Support\Rooms;
use Tests\TestCase;

/*
 * Registration requires the privacy-note agreement (docs/architecture.md §7
 * "Agreements"): "I have read the privacy note". It creates an account with
 * no role; the role and its own sentence come next, on `/role` (ADR 010,
 * tests/Feature/Auth/RoleChoiceTest.php).
 */

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});

/**
 * @return array<string, string>
 */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        ...$overrides,
    ];
}

it('renders the registration screen with the privacy checkbox', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/register'));

    expect(__('auth.register.terms', [], 'az'))->toContain('[')
        ->and(__('auth.register.terms', [], 'en'))->toContain('[');
});

it('registers an account that ticked the privacy checkbox, records when, and gives it no role', function (): void {
    $this->travelTo('2026-11-09 08:30:00');

    $response = $this->post(route('register.store'), registration());

    $this->assertAuthenticated();
    $response->assertRedirect(route('start', absolute: false));

    $user = User::query()->sole();
    expect($user->terms_accepted_at?->toDateTimeString())->toBe('2026-11-09 08:30:00')
        ->and($user->roleList())->toBe([])
        ->and($user->area)->toBeNull()
        ->and($user->teacher_agreed_at)->toBeNull()
        ->and($user->parent_agreed_at)->toBeNull()
        ->and($user->player_agreed_at)->toBeNull();
});

it('asks only for the privacy note, not for a role\'s sentence', function (): void {
    foreach (['az', 'en'] as $locale) {
        expect(__('auth.register.terms', [], $locale))
            ->not->toBe(__('roles.agreement.teacher', [], $locale))
            ->not->toContain(__('roles.agreement.teacher', [], $locale))
            ->not->toContain(__('roles.agreement.parent', [], $locale))
            ->not->toContain(__('roles.agreement.player', [], $locale));
    }
});

it('ignores a role sent with the registration form', function (string $role): void {
    Rooms::on();

    $this->post(route('register.store'), registration(['role' => $role, 'agreement' => '1']))
        ->assertRedirect(route('start', absolute: false));

    $user = User::query()->sole();
    expect($user->roleList())->toBe([])
        ->and($user->getRoleNames()->all())->toBe([])
        ->and($user->player_agreed_at)->toBeNull();
})->with(['teacher', 'player']);

it('refuses a registration without the privacy checkbox', function (array $input): void {
    $this->from(route('register'))
        ->post(route('register.store'), $input)
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors(['terms' => __('auth.register.terms_required')]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'missing' => [fn (): array => array_diff_key(registration(), ['terms' => true])],
    'unticked' => [fn (): array => registration(['terms' => '0'])],
    'empty' => [fn (): array => registration(['terms' => ''])],
]);

it('does not let a profile update set the agreement time', function (): void {
    $user = User::factory()->create(['terms_accepted_at' => null]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => $user->email,
        'terms_accepted_at' => '2020-01-01 00:00:00',
    ]);

    expect($user->fresh()?->terms_accepted_at)->toBeNull();
});

/*
 * The invitation's door (docs/architecture.md §12 "The invitation link
 * (I2)", ADR 033 decisions 10–14): a visitor who opened a game's link by
 * hand makes an account there with the player role in the same step, its
 * two agreements apart, and goes on into the game once the address is
 * confirmed. `/register` stays as it was.
 */

/** A browser's top-level navigation that a person began. */
const DOOR_BY_HAND = ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];

/**
 * The door's form.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function doorForm(array $overrides = []): array
{
    return [
        'name' => 'Nigar Əliyeva',
        'email' => 'Nigar@Example.com',
        'password' => 'password',
        'remember' => '1',
        'terms' => '1',
        'agreement' => '1',
        ...$overrides,
    ];
}

/**
 * A room, and a visitor who opened its link by hand: at the door.
 *
 * @return array{room: Room, link: string}
 */
function atTheDoor(TestCase $test): array
{
    Rooms::on();
    $room = Rooms::open();
    $link = route('rooms.join', ['code' => (string) $room->code, 't' => RoomJoinLink::tag($room)]);

    $test->get($link, DOOR_BY_HAND)->assertRedirect(route('rooms.invited'));

    return ['room' => $room, 'link' => $link];
}

it('sends a visitor who opened a link by hand to the door, and any other to the login', function (): void {
    ['room' => $room, 'link' => $link] = atTheDoor($this);

    expect(session('url.intended'))->toBe($link)
        ->and(session(RoomInvitation::KEY))->toMatchArray(['code' => (string) $room->code, 'tag' => RoomJoinLink::tag($room)]);

    $this->get(route('rooms.invited'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('rooms/invited'));

    // A preview's fetcher, a prefetch and a browser without fetch metadata: the login, as before.
    foreach ([[], [...DOOR_BY_HAND, 'Sec-Purpose' => 'prefetch'], ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']] as $headers) {
        $this->flushSession();
        $this->get($link, $headers)->assertRedirect(route('login'));
        expect(session(RoomInvitation::KEY))->toBeNull()
            ->and(session('url.intended'))->toBe($link);
    }

    // The door without an invitation is the login.
    $this->get(route('rooms.invited'))->assertRedirect(route('login'));

    // Another room page than the link, opened by hand: the login.
    $this->flushSession();
    $this->get(route('rooms.index'), DOOR_BY_HAND)->assertRedirect(route('login'));
});

it('creates a player account at the door with both agreements stamped apart', function (): void {
    ['link' => $link] = atTheDoor($this);
    $this->travelTo('2026-11-24 19:00:00');

    $response = $this->post(route('rooms.invited.store'), doorForm())->assertRedirect($link);

    $player = User::query()->where('email', 'nigar@example.com')->sole();
    $this->assertAuthenticatedAs($player);
    expect($player->name)->toBe('Nigar Əliyeva')
        ->and($player->roleList())->toBe([Role::Player])
        ->and($player->area)->toBe(Role::Player)
        ->and($player->terms_accepted_at?->toDateTimeString())->toBe('2026-11-24 19:00:00')
        ->and($player->player_agreed_at?->toDateTimeString())->toBe('2026-11-24 19:00:00')
        ->and($player->teacher_agreed_at)->toBeNull()
        ->and($player->parent_agreed_at)->toBeNull()
        ->and($player->email_verified_at)->toBeNull()
        ->and($player->locale)->toBe('az');

    // «Məni xatırla» as ticked (ADR 017).
    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);
    expect($response->getCookie($guard->getRecallerName(), decrypt: false))->not->toBeNull();
});

it('refuses the door without the privacy note, without the player\'s sentence, or with a short password', function (array $input, string $field, string $message): void {
    atTheDoor($this);

    $this->from(route('rooms.invited'))
        ->post(route('rooms.invited.store'), $input)
        ->assertRedirect(route('rooms.invited'))
        ->assertSessionHasErrors([$field => $message]);

    $this->assertGuest();
    expect(User::query()->where('email', 'nigar@example.com')->exists())->toBeFalse();
})->with([
    'no privacy note' => [fn (): array => array_diff_key(doorForm(), ['terms' => true]), 'terms', fn (): string => __('auth.register.terms_required')],
    'the note unticked' => [fn (): array => doorForm(['terms' => '0']), 'terms', fn (): string => __('auth.register.terms_required')],
    'no player\'s sentence' => [fn (): array => array_diff_key(doorForm(), ['agreement' => true]), 'agreement', fn (): string => __('roles.errors.agreement')],
    'the sentence unticked' => [fn (): array => doorForm(['agreement' => '0']), 'agreement', fn (): string => __('roles.errors.agreement')],
    'a short password' => [fn (): array => doorForm(['password' => 'short']), 'password', fn (): string => __('validation.min.string', ['attribute' => __('validation.attributes.password'), 'min' => 8])],
]);

it('asks for the password once at the door', function (): void {
    atTheDoor($this);

    // No repeat is asked for, and a repeat that differs is not read.
    $this->post(route('rooms.invited.store'), doorForm(['password_confirmation' => 'something else']))->assertRedirect();

    $player = User::query()->where('email', 'nigar@example.com')->sole();
    expect(Hash::check('password', (string) $player->password))->toBeTrue();
});

it('sends the confirmation mail and goes to the notice, then into the game once confirmed', function (): void {
    Notification::fake();
    ['room' => $room, 'link' => $link] = atTheDoor($this);

    $this->post(route('rooms.invited.store'), doorForm(['remember' => '0']))->assertRedirect($link);
    $player = User::query()->where('email', 'nigar@example.com')->sole();
    Notification::assertSentTo($player, VerifyEmail::class);

    // The redirect followed: unconfirmed, to the notice, which says a game waits — and never `/role`.
    $this->get($link)->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/verify-email')->where('game', true));
    expect($room->rowOf($player))->toBeNull();

    // The mail's link: confirmed, on to the link, and in.
    $verify = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $player->id, 'hash' => sha1($player->email)]);
    $this->get($verify)->assertRedirect($link);
    $this->get($link)->assertRedirect(route('rooms.show', $room));

    expect($room->rowOf($player)?->isPresent())->toBeTrue()
        ->and(session(RoomInvitation::KEY))->toBeNull();
});

it('renders the door the same for a real code and made-up digits', function (): void {
    ['room' => $room] = atTheDoor($this);
    $real = $this->get(route('rooms.invited'))->assertOk()->viewData('page');

    $this->flushSession();
    $this->get(route('rooms.join', ['code' => $room->code === '999999' ? '999998' : '999999']), DOOR_BY_HAND)->assertRedirect(route('rooms.invited'));
    $made = $this->get(route('rooms.invited'))->assertOk()->viewData('page');

    // The page reads no room: the same component and the same props.
    expect($made['component'])->toBe('rooms/invited')
        ->and(array_keys($made['props']))->toEqualCanonicalizing(array_keys($real['props']))
        ->and($made['props']['passwordRules'])->toBe($real['props']['passwordRules'])
        ->and($made['props']['googleSignIn'])->toBe($real['props']['googleSignIn'])
        ->and(json_encode($real['props']))->not->toContain((string) $room->code)
        ->and(json_encode($real['props']))->not->toContain($room->id);
});

it('sends a signed-in account away from the door', function (): void {
    atTheDoor($this);
    $this->actingAs(User::factory()->player()->create());

    $this->get(route('rooms.invited'))->assertRedirect(route('start'));
    $this->post(route('rooms.invited.store'), doorForm())->assertRedirect(route('start'));

    expect(User::query()->where('email', 'nigar@example.com')->exists())->toBeFalse();
});

it('keeps /register as it was', function (): void {
    atTheDoor($this);

    $this->get(route('register'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')
        ->has('passwordRules')
        ->has('googleSignIn')
        ->missing('player'));

    // The repeated password is still asked for there, and no role is given.
    $this->post(route('register.store'), registration(['password_confirmation' => 'different', 'agreement' => '1']))
        ->assertSessionHasErrors('password');
    $this->post(route('register.store'), registration(['agreement' => '1']))->assertRedirect();

    expect(User::query()->where('email', 'test@example.com')->sole()->roleList())->toBe([]);
});

it('throttles the door per address', function (): void {
    atTheDoor($this);

    for ($i = 0; $i < 30; $i++) {
        $this->post(route('rooms.invited.store'), ['terms' => '0'])->assertSessionHasErrors();
    }

    $this->post(route('rooms.invited.store'), doorForm())->assertStatus(429);
    expect(User::query()->where('email', 'nigar@example.com')->exists())->toBeFalse();
});
