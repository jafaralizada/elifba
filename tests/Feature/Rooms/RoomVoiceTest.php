<?php

use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Rooms;

/*
 * The host's tools of a room's live voice (docs/architecture.md §12 "Live
 * voice", ADR 031 decisions 7–12): off in every room until its host turns
 * it on; on lets in the host and everyone present at that moment, and an
 * account that comes later waits until the host lets it in; only the host
 * admits, mutes and lets speak, never on the host's own row, and a mute
 * stays until the host lifts it; at most `max_rooms` rooms talk at once.
 * Every change is a fact in a row, raises the revision and signals the
 * room, and every device reads it from the state. The voice server is
 * faked (`Rooms::voiceOn()`).
 */

beforeEach(function (): void {
    $this->travelTo('2026-11-24 10:00:00');
    $this->voice = Rooms::voiceOn($this->reverb);
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', $this->host->id)->sole();
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
    $this->tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->turalRow = Rooms::join($this->room, $this->tural);
    app(LeaveRoom::class)->handle($this->room, $this->turalRow);
});

/**
 * The state `$account` reads: the room's voice, and each player's standing by row id.
 *
 * @return array{voice: array{on: bool, agreed: bool}|null, players: array<string, string|null>}
 */
function voiceOf(User $account, Room $room): array
{
    $state = test()->actingAs($account)->getJson(route('rooms.state', $room))->assertOk()->json();

    return [
        'voice' => $state['voice'],
        'players' => array_column($state['players'], 'voice', 'id'),
    ];
}

function turnVoice(User $account, Room $room, bool $on): TestResponse
{
    return test()->actingAs($account)->putJson(route('rooms.voice.update', $room), ['on' => $on]);
}

function standVoice(User $account, Room $room, RoomPlayer $member, string $standing): TestResponse
{
    return test()->actingAs($account)->putJson(route('rooms.members.voice.update', ['room' => $room, 'member' => $member]), ['standing' => $standing]);
}

it('starts off in every room', function (): void {
    $other = Rooms::open();

    expect([$this->room->refresh()->voice_at, $this->room->voice_epoch])->toBe([null, 0])
        ->and([$other->refresh()->voice_at, $other->voice_epoch])->toBe([null, 0])
        ->and(voiceOf($this->aygun, $this->room))->toBe([
            'voice' => ['on' => false, 'agreed' => false],
            'players' => [$this->hostRow->id => null, $this->aygunRow->id => null, $this->turalRow->id => null],
        ])
        ->and(voiceOf($this->host, $this->room)['voice'])->toBe(['on' => false, 'agreed' => false])
        ->and(RoomPlayer::query()->whereNotNull('voice_admitted_at')->orWhereNotNull('voice_muted_at')->count())->toBe(0)
        ->and($this->voice->sent)->toBe([]);
});

it('lets only the host turn the voice on and off', function (): void {
    $outsider = User::factory()->player()->create();

    // A player of the room, and an account outside it, are refused and change nothing.
    turnVoice($this->aygun, $this->room, true)->assertForbidden();
    turnVoice($outsider, $this->room, true)->assertNotFound();
    turnVoice($this->host, $this->room, true)->assertNoContent();
    turnVoice($this->host, $this->room, false)->assertNoContent();
    expect($this->room->refresh()->voice_at)->toBeNull()
        ->and($this->room->voice_epoch)->toBe(1);

    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => 'maybe'])->assertJsonValidationErrors('on');
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), [])->assertJsonValidationErrors('on');

    turnVoice($this->host, $this->room, true)->assertNoContent();
    expect($this->room->refresh()->voice_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and(voiceOf($this->aygun, $this->room)['voice'])->toBe(['on' => true, 'agreed' => false]);

    // A player cannot turn it off; the host can, at any moment.
    turnVoice($this->aygun, $this->room, false)->assertForbidden();
    expect($this->room->refresh()->voice_at)->not->toBeNull();
    turnVoice($this->host, $this->room, false)->assertNoContent();
    expect($this->room->refresh()->voice_at)->toBeNull()
        ->and($this->room->voice_epoch)->toBe(2)
        ->and(voiceOf($this->aygun, $this->room)['voice'])->toBe(['on' => false, 'agreed' => false]);

    // In a game too.
    Rooms::playing($this->room);
    turnVoice($this->host, $this->room, true)->assertNoContent();
    expect($this->room->refresh()->voiceIsOn())->toBeTrue();

    // Not in a closed room.
    $this->actingAs($this->host)->post(route('rooms.close', $this->room));
    turnVoice($this->host, $this->room, true)->assertForbidden();
    expect($this->room->refresh()->voice_at)->toBeNull();
});

it('admits everyone present when the voice is turned on and makes a newcomer wait', function (): void {
    turnVoice($this->host, $this->room, true)->assertNoContent();

    // The host and Aygün were in the room: let in. Tural had left: not.
    expect($this->hostRow->refresh()->voice_admitted_at)->not->toBeNull()
        ->and($this->aygunRow->refresh()->voice_admitted_at)->not->toBeNull()
        ->and($this->turalRow->refresh()->voice_admitted_at)->toBeNull()
        ->and(voiceOf($this->aygun, $this->room)['players'])->toBe([
            $this->hostRow->id => 'open',
            $this->aygunRow->id => 'open',
            $this->turalRow->id => null,
        ]);

    // An account that comes after the voice is on waits; so does Tural, back.
    $leyla = User::factory()->player()->create(['name' => 'Leyla Həsənova']);
    $leylaRow = Rooms::join($this->room, $leyla);
    Rooms::join($this->room, $this->tural);

    expect(voiceOf($leyla, $this->room)['players'])->toBe([
        $this->hostRow->id => 'open',
        $this->aygunRow->id => 'open',
        $this->turalRow->id => 'waiting',
        $leylaRow->id => 'waiting',
    ]);

    // A host who stepped out of playing keeps the voice: the host's row is always `open`.
    turnVoice($this->host, $this->room, false)->assertNoContent();
    $this->actingAs($this->host)->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertNoContent();
    turnVoice($this->host, $this->room, true)->assertNoContent();

    expect(voiceOf($this->host, $this->room)['players'])->toBe([
        $this->hostRow->id => 'open',
        $this->aygunRow->id => 'open',
        $this->turalRow->id => 'open',
        $leylaRow->id => 'open',
    ]);
});

it('lets only the host admit, mute and let speak, and never the host\'s own row', function (): void {
    turnVoice($this->host, $this->room, true)->assertNoContent();
    $leyla = User::factory()->player()->create();
    $leylaRow = Rooms::join($this->room, $leyla);
    $elsewhere = Rooms::join(Rooms::open());

    // A player can do none of it, not even to themselves.
    standVoice($this->aygun, $this->room, $leylaRow, 'open')->assertForbidden();
    standVoice($this->aygun, $this->room, $this->aygunRow, 'muted')->assertForbidden();
    // Never the host's own row, and never a row of another room.
    standVoice($this->host, $this->room, $this->hostRow, 'muted')->assertForbidden();
    standVoice($this->host, $this->room, $elsewhere, 'muted')->assertNotFound();
    // Nobody is sent back to wait.
    standVoice($this->host, $this->room, $leylaRow, 'waiting')->assertJsonValidationErrors('standing');

    expect($leylaRow->refresh()->voice_admitted_at)->toBeNull()
        ->and($this->aygunRow->refresh()->voice_muted_at)->toBeNull()
        ->and($this->hostRow->refresh()->voice_muted_at)->toBeNull();

    // «Səsə burax», «Susdur», «Danışmağa icazə ver».
    standVoice($this->host, $this->room, $leylaRow, 'open')->assertNoContent();
    standVoice($this->host, $this->room, $this->aygunRow, 'muted')->assertNoContent();
    expect(voiceOf($leyla, $this->room)['players'])->toBe([
        $this->hostRow->id => 'open',
        $this->aygunRow->id => 'muted',
        $this->turalRow->id => null,
        $leylaRow->id => 'open',
    ]);

    standVoice($this->host, $this->room, $this->aygunRow, 'open')->assertNoContent();
    expect(voiceOf($this->aygun, $this->room)['players'][$this->aygunRow->id])->toBe('open');

    // A newcomer let in muted listens; admitting and muting is one tap.
    $kamran = Rooms::join($this->room);
    standVoice($this->host, $this->room, $kamran, 'muted')->assertNoContent();
    expect(voiceOf($this->host, $this->room)['players'][$kamran->id])->toBe('muted');

    // A removed row changes nothing.
    app(RemoveRoomPlayer::class)->handle($this->room, $kamran);
    standVoice($this->host, $this->room, $kamran->refresh(), 'open')->assertNoContent();
    expect($kamran->refresh()->voice_muted_at)->not->toBeNull();
});

it('keeps a mute through the voice going off and on', function (): void {
    turnVoice($this->host, $this->room, true)->assertNoContent();
    standVoice($this->host, $this->room, $this->aygunRow, 'muted')->assertNoContent();
    $mutedAt = $this->aygunRow->refresh()->voice_muted_at;

    turnVoice($this->host, $this->room, false)->assertNoContent();
    expect(voiceOf($this->aygun, $this->room)['players'][$this->aygunRow->id])->toBeNull();

    $this->travel(5)->minutes();
    turnVoice($this->host, $this->room, true)->assertNoContent();

    expect(voiceOf($this->aygun, $this->room)['players'][$this->aygunRow->id])->toBe('muted')
        ->and($this->aygunRow->refresh()->voice_muted_at?->toDateTimeString())->toBe($mutedAt?->toDateTimeString());

    // Muting again changes nothing; only the host's «Danışmağa icazə ver» lifts it.
    $revision = $this->room->refresh()->revision;
    standVoice($this->host, $this->room, $this->aygunRow, 'muted')->assertNoContent();
    expect($this->room->refresh()->revision)->toBe($revision);
});

it('refuses another voice beyond the limit', function (): void {
    $talking = Room::factory()->count(5)->create(['voice_at' => now()]);
    // A closed room and an idle one do not count, whatever their rows say.
    Room::factory()->closed()->create(['voice_at' => now()]);
    Room::factory()->create(['voice_at' => now(), 'last_activity_at' => now()->subHours(4)]);

    turnVoice($this->host, $this->room, true)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['on' => __('rooms.voice.errors.busy')]);
    expect($this->room->refresh()->voice_at)->toBeNull()
        ->and($this->aygunRow->refresh()->voice_admitted_at)->toBeNull()
        ->and($this->voice->calls('CreateRoom'))->toBe([]);

    // One of them goes quiet: there is room again.
    $talking->first()?->forceFill(['voice_at' => null])->save();
    turnVoice($this->host, $this->room, true)->assertNoContent();
    expect($this->room->refresh()->voiceIsOn())->toBeTrue();

    // Turning off is never refused.
    Room::factory()->create(['voice_at' => now()]);
    turnVoice($this->host, $this->room, false)->assertNoContent();
});

it('signals every device when the voice or a standing changes', function (): void {
    $changes = function (callable $act): array {
        $revision = $this->room->refresh()->revision;
        $this->reverb->sent = [];
        $act();

        return [
            $this->room->refresh()->revision - $revision,
            array_map(static fn (array $sent): array => [$sent['channels'], $sent['event'], $sent['data']], $this->reverb->sent),
        ];
    };
    $signal = fn (int $revision): array => [[['private-room.'.$this->room->id], 'room.changed', ['v' => 1, 'revision' => $revision]]];

    [$raised, $sent] = $changes(fn () => turnVoice($this->host, $this->room, true)->assertNoContent());
    expect($raised)->toBe(1)->and($sent)->toBe($signal($this->room->revision));

    [$raised, $sent] = $changes(fn () => turnVoice($this->host, $this->room, true)->assertNoContent());
    expect($raised)->toBe(0)->and($sent)->toBe([]);

    [$raised, $sent] = $changes(fn () => standVoice($this->host, $this->room, $this->aygunRow, 'muted')->assertNoContent());
    expect($raised)->toBe(1)->and($sent)->toBe($signal($this->room->revision));

    $newcomer = Rooms::join($this->room);
    [$raised, $sent] = $changes(fn () => standVoice($this->host, $this->room, $newcomer, 'open')->assertNoContent());
    expect($raised)->toBe(1)->and($sent)->toBe($signal($this->room->revision));

    [$raised, $sent] = $changes(fn () => standVoice($this->host, $this->room, $newcomer, 'open')->assertNoContent());
    expect($raised)->toBe(0)->and($sent)->toBe([]);

    [$raised, $sent] = $changes(fn () => turnVoice($this->host, $this->room, false)->assertNoContent());
    expect($raised)->toBe(1)->and($sent)->toBe($signal($this->room->revision));

    // What every device reads afterwards: the voice off, the facts kept on the rows.
    expect(voiceOf($this->aygun, $this->room)['voice'])->toBe(['on' => false, 'agreed' => false])
        ->and($this->aygunRow->refresh()->voice_muted_at)->not->toBeNull()
        ->and($newcomer->refresh()->voice_admitted_at)->not->toBeNull();
});
