<?php

use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Rooms;

/*
 * Who may join `private-room.{id}` (docs/architecture.md §12 "The race",
 * ADR 021 decision 8), through the real `POST /broadcasting/auth` with the
 * Reverb broadcaster's keys: the host and the present players of an open
 * room, by their session. Nobody else, and nobody once it is closed. A
 * guest board's bearer: tests/Feature/Play/ChannelAuthTest.php; the switch:
 * tests/Feature/Rooms/RoomsSwitchTest.php.
 */

const ROOM_SOCKET_ID = '1234.5678';

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create();
    $this->room = Rooms::open($this->host);
    $this->player = User::factory()->player()->create();
    $this->row = Rooms::join($this->room, $this->player);
});

function joinRoomChannel(Room|string $room): TestResponse
{
    return test()->post('/broadcasting/auth', [
        'socket_id' => ROOM_SOCKET_ID,
        'channel_name' => 'private-room.'.($room instanceof Room ? $room->id : $room),
    ], ['Accept' => 'application/json']);
}

function roomChannelSignature(Room $room): string
{
    return 'elifba-test-key:'.hash_hmac('sha256', ROOM_SOCKET_ID.':private-room.'.$room->id, 'elifba-test-secret');
}

it('authorizes the host and a present player of an open room', function (): void {
    $this->actingAs($this->host);
    joinRoomChannel($this->room)->assertOk()->assertExactJson(['auth' => roomChannelSignature($this->room)]);

    $this->actingAs($this->player);
    joinRoomChannel($this->room)->assertOk()->assertExactJson(['auth' => roomChannelSignature($this->room)]);

    // A host who does not play, too.
    $watcher = User::factory()->player()->create();
    $own = Rooms::open($watcher, plays: false);
    $this->actingAs($watcher);
    joinRoomChannel($own)->assertOk()->assertExactJson(['auth' => roomChannelSignature($own)]);
});

it('refuses a player who left, a removed player, another account and a visitor', function (): void {
    $left = User::factory()->player()->create();
    app(LeaveRoom::class)->handle($this->room, Rooms::join($this->room, $left));
    $removed = User::factory()->player()->create();
    app(RemoveRoomPlayer::class)->handle($this->room, Rooms::join($this->room, $removed));

    foreach ([
        'a player who left' => $left,
        'a removed player' => $removed,
        'another player' => User::factory()->player()->create(),
        'a teacher' => User::factory()->create(),
        'no role yet' => User::factory()->withoutRole()->create(),
    ] as $who => $account) {
        $this->actingAs($account);
        expect(joinRoomChannel($this->room)->status())->toBe(403, $who);
    }

    $this->app['auth']->guard('web')->forgetUser();
    joinRoomChannel($this->room)->assertForbidden();

    // An unknown or malformed room.
    $this->actingAs($this->host);
    joinRoomChannel('01JZ0000000000000000000000')->assertForbidden();
    joinRoomChannel('1')->assertForbidden();
});

it('refuses a player who no longer holds the role\'s area', function (): void {
    // The row stays; an account the role was never granted to cannot listen.
    $this->player->roles()->detach();
    $this->player->forceFill(['player_agreed_at' => null])->save();

    $this->actingAs($this->player->refresh());
    joinRoomChannel($this->room)->assertForbidden();
});

it('refuses everyone once the room is closed', function (): void {
    app(CloseRoom::class)->handle($this->room);

    foreach ([$this->host, $this->player] as $account) {
        $this->actingAs($account);
        joinRoomChannel($this->room)->assertForbidden();
    }

    // An idle room as well, before the scheduler closes it.
    $idle = Rooms::open($this->player);
    $this->travel(config('elifba.rooms.idle_hours'))->hours();
    $this->actingAs($this->player);
    joinRoomChannel($idle)->assertForbidden();
});
