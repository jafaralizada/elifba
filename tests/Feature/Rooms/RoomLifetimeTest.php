<?php

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomCode;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * How long a room lives (docs/architecture.md §1 "Scheduled", §12 "Rooms
 * and joining", "Scoring and what is kept", ADR 020 decision 7, ADR 022
 * decisions 6–7): an idle room counts as closed at once and is closed
 * within the hour; rooms go after the retention period with everything
 * under them; an account takes the rooms it hosted with it and leaves its
 * rows in other rooms without a name.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 18:00:00');
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
});

it('treats an idle room as closed before the scheduler runs', function (): void {
    $room = Rooms::open($this->host);
    Rooms::join($room, $this->player);
    $code = (string) $room->code;

    // A join is activity: the idle time counts from the last one.
    $this->travelTo('2026-11-24 20:59:59');
    expect($room->refresh()->isOpen())->toBeTrue()
        ->and(RoomCode::resolve($code)?->id)->toBe($room->id);

    $this->travelTo('2026-11-24 21:00:00');
    $room->refresh();

    expect($room->closed_at)->toBeNull()
        ->and($room->code)->toBe($code)
        ->and($room->isOpen())->toBeFalse()
        ->and(RoomCode::resolve($code))->toBeNull()
        ->and(Room::query()->open()->exists())->toBeFalse();

    $this->actingAs($this->player)->get(route('rooms.join', $code))->assertNotFound();
    $this->getJson(route('rooms.state', $room))->assertForbidden();
    $this->get(route('rooms.show', $room))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('room.status', 'closed')
        ->where('state.room.code', null)
        ->where('can.forget', true));

    // Nothing in it changes any more: no join, and the host's tools do nothing.
    $this->post(route('rooms.join.store', $code))->assertNotFound();
    $row = RoomPlayer::query()->where('room_id', $room->id)->where('user_id', $this->player->id)->sole();
    $before = [$room->refresh()->toArray(), $row->toArray()];
    $this->reverb->sent = [];

    $this->actingAs($this->host);
    $this->patch(route('rooms.update', $room), ['locked' => true])->assertRedirect();
    $this->delete(route('rooms.members.destroy', [$room, $row]))->assertRedirect();

    expect([$room->refresh()->toArray(), $row->refresh()->toArray()])->toBe($before)
        ->and($this->reverb->sent)->toBe([]);
    $this->actingAs($this->player)->get(route('rooms.show', $room))->assertOk();

    // Its host may open the next room.
    $this->actingAs($this->host)->post(route('rooms.store'))->assertSessionHasNoErrors();
    expect(Room::query()->open()->count())->toBe(1);
});

it('closes idle rooms', function (): void {
    $idle = Rooms::open($this->host);
    $this->travelTo('2026-11-24 19:30:00');
    $active = Rooms::open();
    $joinedLate = Rooms::open();

    $this->travelTo('2026-11-24 22:00:00');
    Rooms::join($joinedLate, $this->player);
    $this->reverb->sent = [];

    $this->travelTo('2026-11-24 22:15:00');
    $this->artisan('rooms:close-idle')
        ->expectsOutput('Closed 1 rooms with no activity.')
        ->assertSuccessful();

    expect($idle->refresh()->closed_at?->toDateTimeString())->toBe('2026-11-24 22:15:00')
        ->and($idle->code)->toBeNull()
        ->and($active->refresh()->closed_at)->toBeNull()
        ->and($active->isOpen())->toBeTrue()
        ->and($joinedLate->refresh()->closed_at)->toBeNull()
        ->and($this->reverb->sent)->toHaveCount(1)
        ->and($this->reverb->sent[0]['channels'])->toBe(['private-room.'.$idle->id]);

    // The next hour, the second one; then nothing is left to close.
    $this->travelTo('2026-11-24 22:30:01');
    $this->artisan('rooms:close-idle')->expectsOutput('Closed 1 rooms with no activity.');
    $this->artisan('rooms:close-idle')->expectsOutput('Closed 0 rooms with no activity.');

    expect($active->refresh()->closed_at)->not->toBeNull()
        ->and($joinedLate->refresh()->closed_at)->toBeNull();
});

it('prunes rooms past the retention period with everything under them', function (): void {
    $old = Rooms::open($this->host);
    Rooms::join($old, $this->player);
    $this->travel(1)->days();
    $kept = Rooms::open($this->host);
    Rooms::join($kept, $this->player);

    $this->travelTo($old->created_at->addDays(config('elifba.retention_days'))->addSecond());

    $this->artisan('rooms:prune')
        ->expectsOutput('Deleted 1 rooms with their players.')
        ->assertSuccessful();

    expect(Room::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(RoomPlayer::query()->where('room_id', $old->id)->exists())->toBeFalse()
        ->and(RoomPlayer::query()->where('room_id', $kept->id)->count())->toBe(2)
        ->and(User::query()->count())->toBe(2);
});

it('deletes a host\'s rooms with the account and leaves a player\'s rows without an account', function (): void {
    $hosted = Rooms::open($this->host);
    Rooms::join($hosted, $this->player);
    $elsewhere = Rooms::open($this->player);
    $row = Rooms::join($elsewhere, $this->host);

    $this->actingAs($this->host)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect(User::query()->find($this->host->id))->toBeNull()
        ->and(Room::query()->find($hosted->id))->toBeNull()
        ->and(RoomPlayer::query()->where('room_id', $hosted->id)->exists())->toBeFalse()
        ->and($row->refresh()->user_id)->toBeNull()
        ->and(Room::query()->find($elsewhere->id))->not->toBeNull()
        ->and(DB::table('room_players')->where('user_id', $this->host->id)->exists())->toBeFalse();

    // The other room shows the row without a name.
    $this->actingAs($this->player)->getJson(route('rooms.state', $elsewhere))
        ->assertOk()
        ->assertJsonPath('players.1', ['id' => $row->id, 'name' => null, 'present' => false, 'host' => false]);
});

it('schedules closing every hour and pruning every day', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(static fn (Event $event): array => [(string) preg_replace('/^.*artisan\'?\s+/', '', (string) $event->command) => $event->expression]);

    expect($events->get('rooms:close-idle'))->toBe('0 * * * *')
        ->and($events->get('rooms:prune'))->toBe('0 0 * * *');
});
