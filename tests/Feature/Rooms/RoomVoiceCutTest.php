<?php

use App\Domain\Rooms\Actions\CloseIdleRooms;
use App\Domain\Rooms\Actions\SetRoomPlayerVoice;
use App\Domain\Rooms\Actions\SetRoomVoice;
use App\Domain\Rooms\Data\RoomPlayerVoiceData;
use App\Domain\Rooms\Data\RoomVoiceSwitchData;
use App\Domain\Rooms\Enums\RoomVoiceStanding;
use App\Domain\Rooms\Exceptions\RoomVoiceNotCut;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomVoiceServer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeVoiceServer;
use Tests\Support\Rooms;

/*
 * Enforcement at the voice server (docs/architecture.md §12 "Live voice",
 * ADR 031 decisions 22–24): an action tells the voice server what changed
 * after its commit and after `room.changed`, never inside the transaction
 * — a mute with the whole permission set, a removal, the voice going off
 * and a close by deleting the voice room under a new epoch, a leave by
 * taking the player out — and nothing for a lock or an admission. A voice
 * server that is down fails no write. The sweep makes the voice server
 * match the facts, closing what no call covered and, under a new epoch,
 * what a replayed ticket let in.
 */

beforeEach(function (): void {
    $this->travelTo('2026-11-24 10:00:00');
    $this->voice = Rooms::voiceOn($this->reverb);
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', $this->host->id)->sole();
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(true));
    $this->room->refresh();
    // Both took a ticket and are in the voice room, microphones allowed.
    $this->voice->joins($this->room->voiceRoom(), $this->hostRow->id);
    $this->voice->joins($this->room->voiceRoom(), $this->aygunRow->id);
    $this->voice->sent = [];
});

/** The whole permission set an `UpdateParticipant` sends. */
function voicePermission(bool $canPublish): array
{
    return [
        'can_subscribe' => true,
        'can_publish' => $canPublish,
        'can_publish_data' => false,
        'can_publish_sources' => ['MICROPHONE'],
        'can_update_metadata' => false,
        'hidden' => false,
        'recorder' => false,
    ];
}

it('tells the voice server after the commit and after the signal', function (): void {
    $level = DB::transactionLevel();
    $before = count($this->reverb->sent);

    $this->actingAs($this->host)->putJson(route('rooms.members.voice.update', ['room' => $this->room, 'member' => $this->aygunRow]), ['standing' => 'muted'])->assertNoContent();
    $this->actingAs($this->host)->delete(route('rooms.members.destroy', ['room' => $this->room, 'member' => $this->aygunRow]));
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => true])->assertNoContent();
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => false])->assertNoContent();

    expect(array_column($this->voice->sent, 'call'))->toBe(['UpdateParticipant', 'DeleteRoom', 'DeleteRoom']);

    // Each after its own `room.changed`, with no transaction open (so no lock held).
    foreach ($this->voice->sent as $index => $sent) {
        expect($sent['level'])->toBe($level, $sent['call'])
            ->and($sent['broadcasts'])->toBe($before + $index + 1, $sent['call']);
    }

    // On again after off: the voice room of the new epoch is made, after its signal too.
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => true])->assertNoContent();
    $create = $this->voice->calls('CreateRoom')[0];
    expect($create['body'])->toBe(['name' => $this->room->id.'.2', 'empty_timeout' => 300, 'departure_timeout' => 20, 'max_participants' => Config::integer('elifba.rooms.max_players') + 1])
        ->and($create['grant'])->toBe(['roomCreate' => true])
        ->and($create['level'])->toBe($level)
        ->and($create['broadcasts'])->toBe(count($this->reverb->sent));
});

it('unpublishes a muted player with the whole permission set', function (): void {
    $name = $this->room->voiceRoom();

    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    $mute = $this->voice->calls('UpdateParticipant')[0];

    expect($mute['body'])->toBe(['room' => $name, 'identity' => $this->aygunRow->id, 'permission' => voicePermission(false)])
        ->and($mute['grant'])->toBe(['roomAdmin' => true, 'room' => $name])
        ->and($this->voice->rooms[$name][$this->aygunRow->id])->toBeFalse();

    // Let speak again: the permission comes back; the microphone stays as its owner left it.
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    expect($this->voice->calls('UpdateParticipant')[1]['body'])->toBe(['room' => $name, 'identity' => $this->aygunRow->id, 'permission' => voicePermission(true)])
        ->and($this->voice->rooms[$name][$this->aygunRow->id])->toBeTrue();

    // A player not in the voice room is no error; one muted while the voice is off is told nothing.
    $this->voice->rooms[$name] = [];
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    app(SetRoomVoice::class)->handle($this->room, new RoomVoiceSwitchData(false));
    $calls = count($this->voice->sent);
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));

    expect($this->voice->sent)->toHaveCount($calls)
        ->and($this->aygunRow->refresh()->voice_muted_at)->toBeNull();
});

it('closes the voice room under a new epoch on a removal, the voice going off and a close', function (): void {
    $deleted = fn (): array => array_map(static fn (array $sent): mixed => $sent['body']['room'], $this->voice->calls('DeleteRoom'));

    // A removal: everyone is cut, and the others come back under the new epoch.
    $this->actingAs($this->host)->delete(route('rooms.members.destroy', ['room' => $this->room, 'member' => $this->aygunRow]));
    expect($this->room->refresh()->voice_epoch)->toBe(1)
        ->and($this->room->voiceIsOn())->toBeTrue()
        ->and($deleted())->toBe([$this->room->id.'.0'])
        ->and($this->voice->rooms)->toBe([]);

    // The voice turned off.
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => false])->assertNoContent();
    expect($this->room->refresh()->voice_epoch)->toBe(2)
        ->and($deleted())->toBe([$this->room->id.'.0', $this->room->id.'.1']);

    // The room closed by its host.
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => true])->assertNoContent();
    expect($this->voice->rooms)->toHaveKey($this->room->id.'.2');
    $this->actingAs($this->host)->post(route('rooms.close', $this->room));
    expect($this->room->refresh()->voice_epoch)->toBe(3)
        ->and($this->room->voice_at)->toBeNull()
        ->and($deleted())->toBe([$this->room->id.'.0', $this->room->id.'.1', $this->room->id.'.2'])
        ->and($this->voice->rooms)->toBe([]);

    // An idle room, closed by the hourly job: the same, once written.
    $idle = Rooms::open();
    app(SetRoomVoice::class)->handle($idle, new RoomVoiceSwitchData(true));
    $this->travel(4)->hours();
    app(CloseIdleRooms::class)->handle();
    expect($idle->refresh()->voice_epoch)->toBe(1)
        ->and($deleted())->toContain($idle->id.'.0');

    // A room whose voice is off is closed without a call.
    $quiet = Rooms::open();
    $calls = count($this->voice->sent);
    $this->actingAs($quiet->host)->post(route('rooms.close', $quiet));
    expect($this->voice->sent)->toHaveCount($calls)
        ->and($quiet->refresh()->voice_epoch)->toBe(0);
});

it('closes no voice room when the host removes a newcomer who waits', function (): void {
    // A newcomer who came in while a game ran waits: no ticket was ever issued for that row (C5).
    $stranger = Rooms::newcomer($this->room, User::factory()->player()->create(['name' => 'Tural Səfərov']));
    expect($stranger->refresh()->voice_admitted_at)->toBeNull();
    $this->voice->sent = [];

    $this->actingAs($this->host)->delete(route('rooms.members.destroy', ['room' => $this->room, 'member' => $stranger]));

    // Removed for good, and nobody in the voice is cut for it.
    expect($stranger->refresh()->removed_at)->not->toBeNull()
        ->and($this->room->refresh()->voice_epoch)->toBe(0)
        ->and($this->voice->calls('DeleteRoom'))->toBe([])
        ->and($this->voice->rooms)->toHaveKey($this->room->voiceRoom());
});

it('removes a player who left', function (): void {
    $name = $this->room->voiceRoom();

    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));

    expect($this->voice->calls())->toHaveCount(1)
        ->and($this->voice->calls('RemoveParticipant')[0]['body'])->toBe(['room' => $name, 'identity' => $this->aygunRow->id])
        ->and($this->voice->calls('RemoveParticipant')[0]['grant'])->toBe(['roomAdmin' => true, 'room' => $name])
        ->and($this->voice->rooms[$name])->toBe([$this->hostRow->id => true])
        // The epoch stays: the others keep talking.
        ->and($this->room->refresh()->voice_epoch)->toBe(0)
        // What the host decided stays on the row for a return.
        ->and($this->aygunRow->refresh()->voice_admitted_at)->not->toBeNull();

    // The host stepping out of playing keeps the voice: no call.
    $this->actingAs($this->host)->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertNoContent();
    expect($this->voice->calls())->toHaveCount(1)
        ->and($this->voice->rooms[$name])->toHaveKey($this->hostRow->id);
});

it('tells it nothing for a lock or an admission', function (): void {
    $this->actingAs($this->host)->patchJson(route('rooms.update', $this->room), ['locked' => true]);
    $this->actingAs($this->host)->patchJson(route('rooms.update', $this->room), ['locked' => false]);
    $newcomer = Rooms::join($this->room);
    $this->actingAs($this->host)->putJson(route('rooms.members.voice.update', ['room' => $this->room, 'member' => $newcomer]), ['standing' => 'open'])->assertNoContent();
    $this->actingAs($this->host)->putJson(route('rooms.taunts.update', $this->room), ['on' => false])->assertNoContent();
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'anagram', 'difficulty' => 1, 'rounds' => 5])->assertNoContent();

    expect($newcomer->refresh()->voice_admitted_at)->not->toBeNull()
        ->and($this->room->refresh()->locked_at)->toBeNull()
        ->and($this->voice->sent)->toBe([]);
});

it('fails no write when the voice server is down, and reports it without a name', function (): void {
    Exceptions::fake();
    $this->voice->down();

    $this->actingAs($this->host)->putJson(route('rooms.members.voice.update', ['room' => $this->room, 'member' => $this->aygunRow]), ['standing' => 'muted'])->assertNoContent();
    $this->actingAs($this->host)->delete(route('rooms.members.destroy', ['room' => $this->room, 'member' => $this->aygunRow]))->assertRedirect();
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => false])->assertNoContent();
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => true])->assertNoContent();
    $this->actingAs($this->host)->post(route('rooms.close', $this->room))->assertRedirect();

    // Every write stands.
    expect($this->aygunRow->refresh()->voice_muted_at)->not->toBeNull()
        ->and($this->aygunRow->removed_at)->not->toBeNull()
        ->and($this->room->refresh()->closed_at)->not->toBeNull()
        ->and($this->room->voice_at)->toBeNull()
        ->and($this->room->voice_epoch)->toBe(3)
        ->and(array_column($this->voice->sent, 'call'))->toBe(['UpdateParticipant', 'DeleteRoom', 'DeleteRoom', 'CreateRoom', 'DeleteRoom'])
        ->and(array_unique(array_column($this->voice->sent, 'answered')))->toBe([false]);

    // Reported with the room's id and the cause's class, never a name or an identity.
    Exceptions::assertReported(fn (RoomVoiceNotCut $failure): bool => $failure->roomId === $this->room->id
        && str_contains($failure->getMessage(), 'ConnectionException')
        && ! str_contains($failure->getMessage(), 'Aygün')
        && ! str_contains($failure->getMessage(), $this->aygunRow->id));
    Exceptions::assertReportedCount(5);
});

it('sweeps the voice room of a room that is closed, idle, deleted or switched off', function (): void {
    // Six rooms talk at once here, beyond the limit's five.
    Config::set('elifba.rooms.voice.max_rooms', 10);
    $made = static function (): Room {
        $room = Rooms::open();
        app(SetRoomVoice::class)->handle($room, new RoomVoiceSwitchData(true));

        return $room->refresh();
    };
    $closed = $made();
    $idle = $made();
    $deleted = $made();
    $silenced = $made();
    $moved = $made();
    // What the voice server still holds though the facts moved on with no call through.
    Room::query()->whereKey($closed->id)->update(['closed_at' => now()]);
    Room::query()->whereKey($idle->id)->update(['last_activity_at' => now()->subHours(4)]);
    $deletedName = $deleted->voiceRoom();
    $deleted->delete();
    Room::query()->whereKey($silenced->id)->update(['voice_at' => null]);
    Room::query()->whereKey($moved->id)->update(['voice_epoch' => 7]);
    $this->voice->joins('somebody-else', 'x');

    $this->artisan('rooms:voice-sweep')->expectsOutput('Closed 6 voice rooms.')->assertSuccessful();

    expect(array_keys($this->voice->rooms))->toBe([$this->room->voiceRoom()])
        ->and(array_map(static fn (array $sent): mixed => $sent['body']['room'], $this->voice->calls('DeleteRoom')))->toEqualCanonicalizing([
            $closed->voiceRoom(), $idle->voiceRoom(), $deletedName, $silenced->voiceRoom(), $moved->id.'.0', 'somebody-else',
        ])
        // The honest room was listed and left alone; no round, clock or revision was touched.
        ->and($this->room->refresh()->voice_epoch)->toBe(0)
        ->and($this->voice->calls('ListParticipants'))->toHaveCount(1)
        ->and($this->voice->calls('ListRooms')[0]['grant'])->toBe(['roomList' => true])
        ->and($this->voice->calls('ListRooms')[0]['body'])->toBe(['names' => []]);

    // The instance's switch off: every voice room goes.
    Config::set('elifba.rooms.voice.enabled', false);
    $this->artisan('rooms:voice-sweep')->expectsOutput('Closed 1 voice rooms.')->assertSuccessful();
    expect($this->voice->rooms)->toBe([]);

    // A voice server that does not answer: reported, and nothing else happens.
    Exceptions::fake();
    $this->voice->down();
    $this->artisan('rooms:voice-sweep')->expectsOutput('Closed 0 voice rooms.')->assertSuccessful();
    Exceptions::assertReported(RoomVoiceNotCut::class);
});

it('closes the voice room under a new epoch when a ticket was replayed', function (): void {
    $name = $this->room->voiceRoom();
    $sweep = function (): void {
        $this->artisan('rooms:voice-sweep')->assertSuccessful();
    };

    // The honest case: the host, a present player let in, a muted one who does not publish.
    $muted = Rooms::join($this->room);
    app(SetRoomPlayerVoice::class)->handle($this->room, $muted, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    $this->voice->joins($name, $muted->id, false);
    $this->room->refresh();
    $revision = $this->room->revision;
    $sweep();
    expect($this->room->refresh()->voice_epoch)->toBe(0)
        ->and($this->voice->rooms)->toHaveKey($name);

    // A muted player who publishes, more than a minute after the mute: an old ticket replayed.
    $this->travel(61)->seconds();
    $this->voice->joins($name, $muted->id, true);
    $sweep();
    expect($this->room->refresh()->voice_epoch)->toBe(1)
        ->and($this->room->revision)->toBe($revision)
        ->and($this->voice->rooms)->not->toHaveKey($name);

    // A newcomer who waits, inside: a ticket that was never theirs to have.
    $next = $this->room->voiceRoom();
    $waiting = Rooms::newcomer($this->room);
    $this->voice->joins($next, $this->hostRow->id);
    $this->voice->joins($next, $waiting->id, false);
    $sweep();
    expect($this->room->refresh()->voice_epoch)->toBe(2)
        ->and($this->voice->rooms)->not->toHaveKey($next);

    // A player who left more than a minute ago, or an identity of no row of this room.
    $left = fn () => [$this->actingAs($this->aygun)->post(route('rooms.leave', $this->room)), $this->travel(61)->seconds()];

    foreach ([$this->aygunRow->id => $left, '01kaqrx9zz9z9z9z9z9z9z9z9z' => fn () => null] as $identity => $before) {
        $before();
        $current = $this->room->refresh()->voiceRoom();
        $epoch = $this->room->voice_epoch;
        $revision = $this->room->revision;
        $this->voice->joins($current, $this->hostRow->id);
        $this->voice->joins($current, $identity, false);
        $sweep();
        // The sweep raises no revision: the state carries no epoch.
        expect($this->room->refresh()->voice_epoch)->toBe($epoch + 1, $identity)
            ->and($this->room->revision)->toBe($revision, $identity)
            ->and($this->voice->rooms)->not->toHaveKey($current);
    }
});

it('repeats the call of a leave or a mute of the last minute, and gives a player let speak the microphone back', function (): void {
    $name = $this->room->voiceRoom();
    $calls = function (): array {
        $sent = array_map(static fn (array $sent): array => [$sent['call'], $sent['body']['identity'] ?? null, $sent['body']['permission']['can_publish'] ?? null], $this->voice->calls());
        $this->voice->sent = [];

        return array_values(array_filter($sent, static fn (array $call): bool => ! in_array($call[0], ['ListRooms', 'ListParticipants'], true)));
    };

    // Muted, and the ticket signed a moment before the mute joined after its cut: the mute again.
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    $calls();
    $this->voice->joins($name, $this->aygunRow->id, true);
    $this->artisan('rooms:voice-sweep')->expectsOutput('Closed 0 voice rooms.');
    expect($calls())->toBe([['UpdateParticipant', $this->aygunRow->id, false]])
        ->and($this->voice->rooms[$name][$this->aygunRow->id])->toBeFalse()
        ->and($this->room->refresh()->voice_epoch)->toBe(0);

    // Let speak, and the permission did not arrive: it is given back, and nothing else moves.
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    $this->voice->rooms[$name][$this->aygunRow->id] = false;
    $calls();
    $this->artisan('rooms:voice-sweep');
    expect($calls())->toBe([['UpdateParticipant', $this->aygunRow->id, true]])
        ->and($this->voice->rooms[$name][$this->aygunRow->id])->toBeTrue();

    // Left, and still listed while the leave's own cut is on its way: taken out again.
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    $this->voice->joins($name, $this->aygunRow->id);
    $calls();
    $this->artisan('rooms:voice-sweep');
    expect($calls())->toBe([['RemoveParticipant', $this->aygunRow->id, null]])
        ->and($this->voice->rooms[$name])->toBe([$this->hostRow->id => true])
        ->and($this->room->refresh()->voice_epoch)->toBe(0);

    // The honest room as it is: nothing to say.
    $this->artisan('rooms:voice-sweep');
    expect($calls())->toBe([]);

    // A minute later the same leave is a replayed ticket: a new epoch.
    $this->travel(61)->seconds();
    $this->voice->joins($name, $this->aygunRow->id);
    $this->artisan('rooms:voice-sweep')->expectsOutput('Closed 1 voice rooms.');
    expect($calls())->toBe([['DeleteRoom', null, null]])
        ->and($this->room->refresh()->voice_epoch)->toBe(1);
});

it('closes the voice room under a new epoch at once when a muted player or one who left joins again after the fact', function (): void {
    $sweep = fn (): mixed => $this->artisan('rooms:voice-sweep')->assertSuccessful();

    // Muted, and back two seconds later with a ticket or a refreshed token
    // from before the mute that may still publish: not the mute's own cut
    // on its way but a replay, so the first sweep closes the room for all.
    $name = $this->room->voiceRoom();
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    $this->travel(3)->seconds();
    $this->voice->joins($name, $this->aygunRow->id, true);
    $this->voice->sent = [];
    $sweep()->expectsOutput('Closed 1 voice rooms.');
    expect($this->room->refresh()->voice_epoch)->toBe(1)
        ->and($this->voice->calls('UpdateParticipant'))->toBe([])
        ->and($this->voice->rooms)->not->toHaveKey($name);

    // Left, and back a few seconds later the same way: the same.
    $name = $this->room->voiceRoom();
    $this->voice->joins($name, $this->hostRow->id);
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    $this->travel(5)->seconds();
    $this->voice->joins($name, $this->aygunRow->id, false);
    $this->voice->sent = [];
    $sweep()->expectsOutput('Closed 1 voice rooms.');
    expect($this->room->refresh()->voice_epoch)->toBe(2)
        ->and($this->voice->calls('RemoveParticipant'))->toBe([])
        ->and($this->voice->rooms)->not->toHaveKey($name);
});

it('repeats the cut for a join a moment after the mute, made with a ticket signed just before it', function (): void {
    $name = $this->room->voiceRoom();
    $muted = Rooms::join($this->room);
    app(SetRoomPlayerVoice::class)->handle($this->room, $muted, new RoomPlayerVoiceData(RoomVoiceStanding::Open));
    app(SetRoomPlayerVoice::class)->handle($this->room, $muted, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    // The mute is stored to the second; the join lands within the slack after it.
    $this->travel(1500)->milliseconds();
    $this->voice->joins($name, $muted->id, true);
    $this->voice->sent = [];

    $this->artisan('rooms:voice-sweep')->expectsOutput('Closed 0 voice rooms.');

    expect(array_column($this->voice->calls('UpdateParticipant'), 'body'))->toBe([[
        'room' => $name, 'identity' => $muted->id, 'permission' => voicePermission(false),
    ]])->and($this->room->refresh()->voice_epoch)->toBe(0);
});

it('lists when each participant joined, as the voice server says it', function (): void {
    $this->travelTo('2026-11-24 10:00:07.250');
    $this->voice->joins($this->room->voiceRoom(), $this->aygunRow->id, false);

    $participants = app(RoomVoiceServer::class)->participants($this->room->voiceRoom());

    expect($participants)->toBe([
        ['identity' => $this->hostRow->id, 'can_publish' => true, 'joined_at_ms' => CarbonImmutable::parse('2026-11-24 10:00:00')->getTimestampMs()],
        ['identity' => $this->aygunRow->id, 'can_publish' => false, 'joined_at_ms' => CarbonImmutable::parse('2026-11-24 10:00:07.250')->getTimestampMs()],
    ]);
});

it('signs each of its own calls with a token for that kind of call alone, for half a minute', function (): void {
    app(SetRoomPlayerVoice::class)->handle($this->room, $this->aygunRow, new RoomPlayerVoiceData(RoomVoiceStanding::Muted));
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    $this->artisan('rooms:voice-sweep')->assertSuccessful();
    app(SetRoomVoice::class)->handle($this->room->refresh(), new RoomVoiceSwitchData(false));
    app(SetRoomVoice::class)->handle($this->room->refresh(), new RoomVoiceSwitchData(true));

    $grants = [];

    foreach (Http::recorded() as [$request]) {
        $call = basename((string) parse_url($request->url(), PHP_URL_PATH));
        JWT::$timestamp = now()->getTimestamp();
        $claims = json_decode((string) json_encode(JWT::decode(
            str_replace('Bearer ', '', $request->header('Authorization')[0]),
            new Key(FakeVoiceServer::SECRET, 'HS256'),
        )), true);
        JWT::$timestamp = null;

        // The app's own name, no join, no name of anyone's, half a minute.
        expect($claims['exp'] - $claims['nbf'])->toBe(30, $call)
            ->and($claims['sub'])->toBe('elifba', $call)
            ->and($claims)->not->toHaveKey('name')
            ->and($claims['video'])->not->toHaveKey('roomJoin');
        $grants[$call][] = $claims['video'];
    }

    $room = $this->room->id.'.0';
    expect(array_keys($grants))->toEqualCanonicalizing(['CreateRoom', 'DeleteRoom', 'UpdateParticipant', 'RemoveParticipant', 'ListRooms', 'ListParticipants'])
        ->and(array_unique($grants['CreateRoom'], SORT_REGULAR))->toBe([['roomCreate' => true]])
        ->and(array_unique($grants['DeleteRoom'], SORT_REGULAR))->toBe([['roomCreate' => true]])
        ->and(array_unique($grants['UpdateParticipant'], SORT_REGULAR))->toBe([['roomAdmin' => true, 'room' => $room]])
        ->and(array_unique($grants['RemoveParticipant'], SORT_REGULAR))->toBe([['roomAdmin' => true, 'room' => $room]])
        ->and(array_unique($grants['ListParticipants'], SORT_REGULAR))->toBe([['roomAdmin' => true, 'room' => $room]])
        ->and(array_unique($grants['ListRooms'], SORT_REGULAR))->toBe([['roomList' => true]]);
});
