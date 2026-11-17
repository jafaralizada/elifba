<?php

use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Exceptions\RoomChangeNotBroadcast;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\Rooms;

/*
 * `room.changed` on the room's channel (docs/architecture.md §12 "The
 * race", ADR 021 decisions 8–9): `{v: 1, revision}` and nothing else, sent
 * at once after the change commits — a join, a leave, a removal, the lock,
 * the close, a game started or ended, an early close; never a single
 * answer. Reverb being down never fails the change. Nothing reaches a
 * real Reverb: tests/Support/FakeReverb records what would have been
 * published.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->player = User::factory()->player()->create(['name' => 'Aygün Məmmədova', 'email' => 'aygun@example.com']);
});

it('signals a change with the revision and nothing else', function (): void {
    $code = (string) $this->room->code;
    $other = User::factory()->player()->create(['name' => 'Tural Əliyev']);

    $this->actingAs($this->player)->post(route('rooms.join.store', $code));
    $this->actingAs($other)->post(route('rooms.join.store', $code));
    $this->actingAs($other)->post(route('rooms.leave', $this->room));
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => true]);
    $this->patch(route('rooms.update', $this->room), ['locked' => false]);
    $this->delete(route('rooms.members.destroy', [$this->room, RoomPlayer::query()->where('user_id', $this->player->id)->sole()]));
    $this->post(route('rooms.close', $this->room));

    // Opening a room, reading it and refusals say nothing.
    $this->getJson(route('rooms.state', $this->room));
    $this->actingAs($other)->post(route('rooms.join.store', $code));

    expect($this->reverb->sent)->toBe(array_map(fn (int $revision): array => [
        'channels' => ['private-room.'.$this->room->id],
        'event' => 'room.changed',
        'data' => ['v' => 1, 'revision' => $revision],
    ], range(1, 7)))->and($this->room->refresh()->revision)->toBe(7);

    $wire = json_encode($this->reverb->sent, JSON_UNESCAPED_UNICODE);
    foreach (['Rəşad', 'Aygün', 'Tural', 'aygun@example.com', $code] as $secret) {
        expect($wire)->not->toContain($secret);
    }
});

it('signals an early close and a game\'s start', function (): void {
    $this->freezeTime();
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    Rooms::join($this->room, $this->player);
    Rooms::join($this->room, $tural);
    $this->reverb->sent = [];
    $setup = ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5];

    // The start: revision 3.
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), $setup)->assertNoContent();
    $game = RoomGame::query()->sole();
    [$first, $second] = [Rooms::round($game, 0), Rooms::round($game, 1)];

    // Single answers, a refused one and the phases time brings: nothing.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    foreach ([$this->host, $this->player] as $account) {
        $this->actingAs($account)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($first, Rooms::right($first)))->assertJsonPath('status', 'accepted');
    }
    $this->actingAs($this->host)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($first, Rooms::right($first)))->assertJsonPath('status', 'answered');
    $this->getJson(route('rooms.state', $this->room));
    expect($this->reverb->sent)->toHaveCount(1);

    // The last present player answers: the early close, revision 4.
    $this->actingAs($tural)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($first, Rooms::wrong($first)))
        ->assertExactJson(['status' => 'accepted', 'revision' => 4]);

    // Round 1: two answer, the third leaves — the leave and the early close are one change, revision 5.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    foreach ([$this->host, $this->player] as $account) {
        $this->actingAs($account)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($second, Rooms::right($second)));
    }
    $this->actingAs($tural)->post(route('rooms.leave', $this->room));
    expect($second->refresh()->closed_ms)->toBe(RoomClock::nowMs());

    // The host ends the game: revision 6.
    $this->actingAs($this->host)->deleteJson(route('rooms.game.destroy', $this->room))->assertNoContent();

    expect($this->reverb->sent)->toBe(array_map(fn (int $revision): array => [
        'channels' => ['private-room.'.$this->room->id],
        'event' => 'room.changed',
        'data' => ['v' => 1, 'revision' => $revision],
    ], range(3, 6)))->and($this->room->refresh()->revision)->toBe(6);

    // No name, item, answer or result on the wire.
    $wire = json_encode($this->reverb->sent, JSON_UNESCAPED_UNICODE);
    foreach (['Rəşad', 'Aygün', 'Tural', $game->id, $first->id, ...Rooms::right($first), ...Rooms::right($second)] as $secret) {
        expect($wire)->not->toContain($secret);
    }
});

it('sends nothing until the change commits, and nothing when it rolls back', function (): void {
    DB::transaction(function (): void {
        app(JoinRoom::class)->handle($this->room, $this->player);

        expect($this->reverb->sent)->toBe([]);
    });

    expect($this->reverb->sent)->toHaveCount(1);

    try {
        DB::transaction(function (): void {
            app(JoinRoom::class)->handle($this->room, User::factory()->player()->create());

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->reverb->sent)->toHaveCount(1)
        ->and($this->room->refresh()->revision)->toBe(1);
});

it('keeps the change when Reverb is down', function (): void {
    Exceptions::fake();
    $this->reverb->down();

    $this->actingAs($this->player)
        ->post(route('rooms.join.store', (string) $this->room->code))
        ->assertRedirect(route('rooms.show', $this->room));

    expect(RoomPlayer::query()->where('user_id', $this->player->id)->sole()->isPresent())->toBeTrue()
        ->and($this->room->refresh()->revision)->toBe(1)
        ->and($this->reverb->sent)->toBe([]);

    Exceptions::assertReported(fn (RoomChangeNotBroadcast $e): bool => $e->roomId === $this->room->id
        && $e->revision === 1
        && $e->getPrevious() === null
        && str_contains($e->getMessage(), $this->room->id)
        && ! str_contains($e->getMessage(), 'Aygün')
        && ! str_contains($e->getMessage(), 'Rəşad'));

    // The page without a socket still learns it from the state.
    $this->actingAs($this->host)->getJson(route('rooms.state', $this->room))
        ->assertJsonPath('revision', 1)
        ->assertJsonPath('players.1.name', 'Aygün Məmmədova');
});
