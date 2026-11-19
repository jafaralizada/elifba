<?php

use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Rooms;

/*
 * The host's tools of a room's live voice (docs/architecture.md §12 "Live
 * voice", ADR 031 decisions 7–12 and, as amended 2026-11-19, 37–39): on
 * from the opening in every room opened while the instance's voice is on,
 * with the host let in, until the host turns it off; on lets in the host
 * and everyone present at that moment; an account that comes in before a
 * game is let in at once, and one that comes in while a game runs waits
 * until the host lets it in; only the host admits, mutes and lets speak,
 * never on the host's own row, and a mute stays until the host lifts it;
 * «on» is never refused — the limit is the ticket's. Every change is a
 * fact in a row, raises the revision and signals the room, and every
 * device reads it from the state. The room of the set-up was opened with
 * the voice off, as one opened before the amendment or with the switch
 * off. The voice server is faked (`Rooms::voiceOn()`).
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

it('starts on in every room opened while the voice switch is on, with the host let in, and calls no voice server', function (): void {
    // A game chosen on «Oyunlar»: the room opens behind it, its voice on and its host let in.
    $nigar = User::factory()->player()->create(['name' => 'Nigar Rzayeva']);
    $this->actingAs($nigar)->post(route('rooms.store'), ['mechanic' => 'recall_order'])->assertRedirect();
    $opened = Room::query()->where('user_id', $nigar->id)->sole();
    $nigarRow = RoomPlayer::query()->where('room_id', $opened->id)->sole();

    expect($opened->voice_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and([$opened->voice_epoch, $opened->revision])->toBe([0, 0])
        ->and($nigarRow->voice_admitted_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and($nigarRow->voice_muted_at)->toBeNull()
        ->and(voiceOf($nigar, $opened))->toBe([
            'voice' => ['on' => true, 'agreed' => false],
            'players' => [$nigarRow->id => 'open'],
        ])
        // Opening asks the voice server nothing: the first ticket makes the voice room.
        ->and($this->voice->sent)->toBe([]);

    // Another game chosen in it leaves the voice as it is, on or off.
    $this->actingAs($nigar)->post(route('rooms.store'), ['mechanic' => 'anagram'])->assertRedirect(route('rooms.show', $opened));
    expect($opened->refresh()->voice_at?->toDateTimeString())->toBe('2026-11-24 10:00:00');
    turnVoice($nigar, $opened, false)->assertNoContent();
    $this->actingAs($nigar)->post(route('rooms.store'), ['mechanic' => 'recall_order'])->assertRedirect(route('rooms.show', $opened));
    expect($opened->refresh()->voice_at)->toBeNull()
        ->and($opened->voice_epoch)->toBe(1);

    // A room opened with the instance's voice off keeps it off: nobody is let in.
    expect([$this->room->refresh()->voice_at, $this->room->voice_epoch])->toBe([null, 0])
        ->and(voiceOf($this->aygun, $this->room))->toBe([
            'voice' => ['on' => false, 'agreed' => false],
            'players' => [$this->hostRow->id => null, $this->aygunRow->id => null, $this->turalRow->id => null],
        ])
        ->and(RoomPlayer::query()->where('room_id', $this->room->id)->whereNotNull('voice_admitted_at')->count())->toBe(0);
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

it('admits everyone present when the voice is turned on, lets in at once a player who comes in before a game, or between games, and makes one who comes in during a game wait', function (): void {
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

    // Before a game the host sees the list grow: an account that comes in is let in at once, and so is Tural, back with a row never let in.
    $leyla = User::factory()->player()->create(['name' => 'Leyla Həsənova']);
    $leylaRow = Rooms::join($this->room, $leyla);
    Rooms::join($this->room, $this->tural);

    expect(voiceOf($leyla, $this->room)['players'])->toBe([
        $this->hostRow->id => 'open',
        $this->aygunRow->id => 'open',
        $this->turalRow->id => 'open',
        $leylaRow->id => 'open',
    ]);

    // While a game runs the host's eyes are on the round: a newcomer waits; a player who left and comes back keeps what the row had.
    Rooms::playing($this->room);
    $kamran = User::factory()->player()->create(['name' => 'Kamran Nəsirov']);
    $kamranRow = Rooms::join($this->room, $kamran);
    app(LeaveRoom::class)->handle($this->room, $leylaRow->refresh());
    Rooms::join($this->room, $leyla);

    expect($kamranRow->refresh()->voice_admitted_at)->toBeNull()
        ->and(voiceOf($kamran, $this->room)['players'])->toBe([
            $this->hostRow->id => 'open',
            $this->aygunRow->id => 'open',
            $this->turalRow->id => 'open',
            $leylaRow->id => 'open',
            $kamranRow->id => 'waiting',
        ]);

    // The final, between games: a newcomer is let in at once; Kamran, still in the room, waits for the host and not for the game's end.
    $this->actingAs($this->host)->delete(route('rooms.game.destroy', $this->room));
    expect($this->room->refresh()->runningGame(RoomClock::nowMs()))->toBeNull();
    $samir = User::factory()->player()->create(['name' => 'Samir Quliyev']);
    $samirRow = Rooms::join($this->room, $samir);

    expect(voiceOf($samir, $this->room)['players'])->toBe([
        $this->hostRow->id => 'open',
        $this->aygunRow->id => 'open',
        $this->turalRow->id => 'open',
        $leylaRow->id => 'open',
        $kamranRow->id => 'waiting',
        $samirRow->id => 'open',
    ]);

    // Kamran leaves and comes back between games: a row never let in comes in like any newcomer.
    app(LeaveRoom::class)->handle($this->room, $kamranRow->refresh());
    Rooms::join($this->room, $kamran);
    expect(voiceOf($kamran, $this->room)['players'][$kamranRow->id])->toBe('open');

    // With the voice off nobody is let in, before a game or not; a host who stepped out of playing keeps the voice: the host's row is always `open`.
    turnVoice($this->host, $this->room, false)->assertNoContent();
    $nigarRow = Rooms::join($this->room, User::factory()->player()->create(['name' => 'Nigar Rzayeva']));
    expect($nigarRow->refresh()->voice_admitted_at)->toBeNull();
    $this->actingAs($this->host)->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertNoContent();
    turnVoice($this->host, $this->room, true)->assertNoContent();

    expect(voiceOf($this->host, $this->room)['players'])->toBe([
        $this->hostRow->id => 'open',
        $this->aygunRow->id => 'open',
        $this->turalRow->id => 'open',
        $leylaRow->id => 'open',
        $kamranRow->id => 'open',
        $samirRow->id => 'open',
        $nigarRow->id => 'open',
    ]);
});

it('lets only the host admit, mute and let speak, and never the host\'s own row', function (): void {
    turnVoice($this->host, $this->room, true)->assertNoContent();
    $leyla = User::factory()->player()->create();
    $leylaRow = Rooms::newcomer($this->room, $leyla);
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
    $kamran = Rooms::newcomer($this->room);
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

it('never refuses to turn the voice on', function (): void {
    // Five other rooms with their voice on and someone in each voice room: the limit is the ticket's (ADR 031 decision 38).
    foreach (Room::factory()->count(5)->create(['voice_at' => now()]) as $talking) {
        $this->voice->joins($talking->voiceRoom(), '01kaqrx2ab3c4d5e6f7g8h9jkm');
    }

    turnVoice($this->host, $this->room, true)->assertNoContent();
    expect($this->room->refresh()->voiceIsOn())->toBeTrue()
        ->and($this->aygunRow->refresh()->voice_admitted_at)->not->toBeNull();

    turnVoice($this->host, $this->room, false)->assertNoContent();
    turnVoice($this->host, $this->room, true)->assertNoContent();
    expect($this->room->refresh()->voiceIsOn())->toBeTrue()
        ->and($this->voice->calls('ListRooms'))->toBe([]);
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

    $newcomer = Rooms::newcomer($this->room);
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
