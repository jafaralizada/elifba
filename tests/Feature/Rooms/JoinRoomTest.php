<?php

use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomCode;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GuestBoards;
use Tests\Support\Rooms;

/*
 * Joining a room with its code (docs/architecture.md §12 "Rooms and
 * joining", ADR 020 decisions 3–4): `/otaq`, `/otaq/{code}` and «Qoşul»
 * admit a signed-in, confirmed account with the player role into that one
 * room. An unknown code, a closed room and an account the host removed get
 * the same 404; a locked or full room says so and nothing else. A room code
 * and a pairing code know nothing of each other.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
});

/**
 * Everything a join could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function joinSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['rooms' => $rows('rooms'), 'room_players' => $rows('room_players')];
}

it('joins with the code and an account', function (): void {
    $code = (string) $this->room->code;
    $spaced = substr($code, 0, 3).' '.substr($code, 3);

    $this->actingAs($this->player);

    // The code field, then the join page: the code and the account's own name.
    $this->get(route('rooms.entrance'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rooms/join')->where('code', null));
    $this->get(route('rooms.entrance', ['code' => $spaced]))->assertRedirect(route('rooms.join', $code));
    $this->get(route('rooms.join', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/join')
            ->where('code', $code)
            ->where('name', 'Aygün Məmmədova')
            ->missing('room')
            ->missing('players'));

    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));

    $row = RoomPlayer::query()->where('user_id', $this->player->id)->sole();
    expect($row->room_id)->toBe($this->room->id)
        ->and($row->isPresent())->toBeTrue()
        ->and($this->room->refresh()->revision)->toBe(1)
        ->and($this->reverb->sent)->toBe([[
            'channels' => ['private-room.'.$this->room->id],
            'event' => 'room.changed',
            'data' => ['v' => 1, 'revision' => 1],
        ]]);

    // Both sit in the lobby and see each other's name.
    $this->getJson(route('rooms.state', $this->room))
        ->assertOk()
        ->assertJsonPath('me.player_id', $row->id)
        ->assertJsonPath('players.0.name', 'Rəşad Quliyev')
        ->assertJsonPath('players.1.name', 'Aygün Məmmədova');
    $this->actingAs($this->host)->getJson(route('rooms.state', $this->room))
        ->assertJsonPath('players.1', ['id' => $row->id, 'name' => 'Aygün Məmmədova', 'present' => true, 'host' => false]);

    // A present player is sent on to the room; joining again changes nothing.
    $before = joinSnapshot();
    $this->actingAs($this->player)->get(route('rooms.join', $code))->assertRedirect(route('rooms.show', $this->room));
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));
    expect(joinSnapshot())->toBe($before);
});

it('sends a visitor to the login and back to the code', function (): void {
    $code = (string) $this->room->code;

    $this->get(route('rooms.join', $code))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $this->player->email, 'password' => 'password'])
        ->assertRedirect(route('rooms.join', $code));

    $this->get(route('rooms.join', $code))->assertOk()->assertInertia(fn (Assert $page) => $page->where('code', $code));
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));
});

it('answers 404 for an unknown code, a closed room and a removed account alike', function (): void {
    $closed = Rooms::open();
    $closedCode = (string) $closed->code;
    app(CloseRoom::class)->handle($closed);

    $removed = User::factory()->player()->create();
    app(RemoveRoomPlayer::class)->handle($this->room, Rooms::join($this->room, $removed));

    // Idle: closed by the rule before the scheduler clears its code.
    $idle = Room::factory()->create(['last_activity_at' => now()->subHours(config('elifba.rooms.idle_hours'))]);

    $unknown = $this->room->code === '000000' ? '000001' : '000000';
    $before = joinSnapshot();

    $refusals = [
        'an unknown code' => [$this->player, $unknown],
        'a closed room' => [$this->player, $closedCode],
        'an idle room' => [$this->player, (string) $idle->code],
        'a removed account' => [$removed, (string) $this->room->code],
    ];

    $answers = [];

    foreach ($refusals as $case => [$account, $code]) {
        $this->actingAs($account);

        foreach ([['GET', route('rooms.join', $code)], ['POST', route('rooms.join.store', $code)], ['GET', route('rooms.entrance', ['code' => $code])]] as [$method, $url]) {
            $response = $this->followingRedirects()->call($method, $url);
            $answers[$case][] = $response->status();

            expect($response->getContent())->not->toContain('Rəşad');
        }

        // A minute apart, so the limit on guessing (below) stays out of it.
        $this->travel(61)->seconds();
    }

    expect($answers)->toBe([
        'an unknown code' => [404, 404, 404],
        'a closed room' => [404, 404, 404],
        'an idle room' => [404, 404, 404],
        'a removed account' => [404, 404, 404],
    ])->and(joinSnapshot())->toBe($before);

    // To a JSON client too, one body for every reason.
    $bodies = [];

    foreach ($refusals as $case => [$account, $code]) {
        $bodies[$case] = $this->actingAs($account)->postJson(route('rooms.join.store', $code))->assertNotFound()->getContent();
        $this->travel(61)->seconds();
    }

    expect(array_unique($bodies))->toHaveCount(1);

    // A malformed code is no room at all.
    $this->actingAs($this->player)->get('/otaq/48291')->assertNotFound();
    $this->get('/otaq/abcdef')->assertNotFound();
    $this->from(route('rooms.entrance'))->get(route('rooms.entrance', ['code' => '48-29']))
        ->assertRedirect(route('rooms.entrance'))
        ->assertSessionHasErrors(['code' => __('rooms.errors.code')]);
});

it('says that a room is locked or full and nothing else about it', function (): void {
    $code = (string) $this->room->code;
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => true])->assertRedirect();
    $before = joinSnapshot();

    $this->actingAs($this->player)->get(route('rooms.join', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('code', $code)->missing('locked')->missing('players'));

    $locked = $this->from(route('rooms.join', $code))->post(route('rooms.join.store', $code));
    $locked->assertRedirect(route('rooms.join', $code))->assertSessionHasErrors(['code' => __('rooms.errors.locked')]);
    expect(session('errors')->all())->toBe([__('rooms.errors.locked')])
        ->and(joinSnapshot())->toBe($before);

    // Full: as many present players as the room may hold.
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => false]);
    Config::set('elifba.rooms.max_players', 2);
    Rooms::join($this->room);
    $before = joinSnapshot();

    $this->actingAs($this->player)->from(route('rooms.join', $code))->post(route('rooms.join.store', $code))
        ->assertRedirect(route('rooms.join', $code))
        ->assertSessionHasErrors(['code' => __('rooms.errors.full')]);
    expect(session('errors')->all())->toBe([__('rooms.errors.full')])
        ->and(joinSnapshot())->toBe($before);

    $this->get(route('rooms.join', $code))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('code', $code)
        ->where('name', 'Aygün Məmmədova')
        ->missing('players'));
});

it('brings a player who left back to the same row', function (): void {
    $this->travelTo('2026-11-24 19:00:00');
    $this->room = Rooms::open();
    $row = Rooms::join($this->room, $this->player);

    $this->travelTo('2026-11-24 19:10:00');
    $this->actingAs($this->player)->post(route('rooms.leave', $this->room))->assertRedirect(route('rooms.index'));
    expect($row->refresh()->left_at?->toDateTimeString())->toBe('2026-11-24 19:10:00');

    // Out of the live room: the page without the state, no state, no channel.
    $this->get(route('rooms.show', $this->room))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('state', null)
        ->where('can.leave', false));
    $this->getJson(route('rooms.state', $this->room))->assertForbidden();

    $this->travelTo('2026-11-24 19:20:00');
    $this->get(route('rooms.join', (string) $this->room->code))->assertOk();
    $this->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));

    $back = RoomPlayer::query()->where('user_id', $this->player->id)->sole();
    expect($back->id)->toBe($row->id)
        ->and($back->left_at)->toBeNull()
        ->and($back->joined_at->toDateTimeString())->toBe('2026-11-24 19:00:00')
        ->and($back->isPresent())->toBeTrue()
        ->and($this->room->refresh()->revision)->toBe(3);
});

it('refuses a twenty-first player', function (): void {
    expect(Config::integer('elifba.rooms.max_players'))->toBe(20);

    foreach (range(2, 20) as $_) {
        Rooms::join($this->room);
    }

    expect($this->room->players()->present()->count())->toBe(20);

    $this->actingAs($this->player)->from(route('rooms.join', (string) $this->room->code))
        ->post(route('rooms.join.store', (string) $this->room->code))
        ->assertSessionHasErrors(['code' => __('rooms.errors.full')]);

    expect($this->room->players()->count())->toBe(20);

    // One leaves, and there is a place again.
    $leaving = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', '!=', $this->host->id)->firstOrFail();
    $this->actingAs(User::query()->findOrFail($leaving->user_id))->post(route('rooms.leave', $this->room));
    $this->actingAs($this->player)->post(route('rooms.join.store', (string) $this->room->code))
        ->assertRedirect(route('rooms.show', $this->room));
});

it('does not know a pairing code, and the pult does not know a room code', function (): void {
    $teacher = User::factory()->teacherAndPlayer()->create();
    $board = GuestBoards::open()['board'];
    $boardCode = (string) $board->code;

    // A board's code at the room's entrance; the room's code at the pult.
    $this->actingAs($teacher)->get(route('rooms.join', $boardCode))->assertNotFound();
    $this->post(route('rooms.join.store', $boardCode))->assertNotFound();
    $this->get(route('pult.code', (string) $this->room->code))->assertNotFound();

    // The same six digits as a room and a board at once: each entrance finds its own.
    app()->instance(RoomCode::class, new RoomCode(static fn (): string => $boardCode));
    $twin = Rooms::open();
    expect($twin->code)->toBe($boardCode);

    $this->get(route('rooms.join', $boardCode))->assertOk()->assertInertia(fn (Assert $page) => $page->component('rooms/join'));
    $this->get(route('pult.code', $boardCode))->assertOk()->assertInertia(fn (Assert $page) => $page->component('pult/guest-board'));

    expect(Room::query()->whereKey($twin->id)->value('code'))->toBe($boardCode)
        ->and($board->refresh()->claimed_at)->toBeNull();
});

it('limits guessing per account', function (): void {
    $this->actingAs($this->player);

    foreach (range(1, 4) as $i) {
        $this->get(route('rooms.join', str_pad((string) $i, 6, '9', STR_PAD_LEFT)))->assertNotFound();
        $this->post(route('rooms.join.store', str_pad((string) $i, 6, '8', STR_PAD_LEFT)))->assertNotFound();
    }

    $this->get(route('rooms.entrance'))->assertOk();
    $this->get(route('rooms.entrance', ['code' => '777 777']))->assertRedirect();

    // Ten in a minute, the three routes together; then even the right code waits.
    $this->get(route('rooms.join', (string) $this->room->code))->assertTooManyRequests();
    $this->post(route('rooms.join.store', (string) $this->room->code))->assertTooManyRequests();
    $this->get(route('rooms.entrance'))->assertTooManyRequests();
    expect(RoomPlayer::query()->where('user_id', $this->player->id)->exists())->toBeFalse();

    // Another account is not held back by this one.
    $this->actingAs(User::factory()->player()->create())->get(route('rooms.join', (string) $this->room->code))->assertOk();

    // A minute later the account may try again.
    $this->travel(61)->seconds();
    $this->actingAs($this->player)->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));
});
