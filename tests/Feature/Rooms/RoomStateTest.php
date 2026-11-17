<?php

use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * A room's state without a game (docs/architecture.md §12 "The state",
 * "Adults' privacy"): the room, the viewer and the players by the names
 * their accounts have now — nothing else about anyone. The lobby fixture,
 * tests/Fixtures/rooms/state.lobby.json, is what Vitest parses with the zod
 * schema (resources/js/rooms/contract.test.ts); here the server builds it
 * from rows, byte for byte, and the DTOs round-trip it.
 */

const ROOMS_LOBBY_FIXTURE = __DIR__.'/../../Fixtures/rooms/state.lobby.json';

/**
 * @return array<string, mixed>
 */
function lobbyFixture(): array
{
    $decoded = json_decode((string) file_get_contents(ROOMS_LOBBY_FIXTURE), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : throw new RuntimeException('The lobby fixture is not an object.');
}

beforeEach(function (): void {
    Rooms::on();
});

it('builds the lobby fixture from rows, and the DTOs round-trip it', function (): void {
    $fixture = lobbyFixture();
    $this->travelTo(CarbonImmutable::createFromTimestampMs($fixture['server_ms']));

    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $viewer = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $left = User::factory()->player()->create(['name' => 'Tural Əliyev']);

    $room = Room::factory()->for($host, 'host')->create([
        'id' => $fixture['room']['id'],
        'code' => $fixture['room']['code'],
        'revision' => $fixture['revision'],
    ]);
    [$hostRow, $viewerRow, $leftRow, $namelessRow] = array_column($fixture['players'], 'id');
    $at = static fn (int $minutes): CarbonImmutable => CarbonImmutable::now()->subMinutes(10 - $minutes);

    RoomPlayer::factory()->for($room)->for($host)->create(['id' => $hostRow, 'joined_at' => $at(0)]);
    RoomPlayer::factory()->for($room)->for($viewer)->create(['id' => $viewerRow, 'joined_at' => $at(1)]);
    RoomPlayer::factory()->for($room)->for($left)->left()->create(['id' => $leftRow, 'joined_at' => $at(2)]);
    RoomPlayer::factory()->for($room)->create(['id' => $namelessRow, 'user_id' => null, 'joined_at' => $at(3)]);
    // Removed: not in the lobby at all.
    RoomPlayer::factory()->for($room)->removed()->create(['joined_at' => $at(4)]);

    $this->actingAs($viewer)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson($fixture);

    expect(RoomStateData::fromArray($fixture)->toArray())->toBe($fixture);
});

it('gives the lobby its players by name and never an account id or an address', function (): void {
    $this->freezeTime();
    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev', 'email' => 'resad@example.com']);
    $room = Rooms::open($host);
    $player = User::factory()->player()->create(['name' => 'Aygün Məmmədova', 'email' => 'aygun@example.com']);
    $row = Rooms::join($room, $player);

    foreach ([$host, $player] as $account) {
        $json = $this->actingAs($account)->getJson(route('rooms.state', $room))->assertOk();
        $page = $this->get(route('rooms.show', $room))->assertOk();
        $props = $page->viewData('page')['props'];
        $text = $json->getContent().json_encode([$props['room'], $props['state'], $props['can']], JSON_UNESCAPED_UNICODE);

        expect($json->json('players.*.name'))->toBe(['Rəşad Quliyev', 'Aygün Məmmədova'])
            ->and($props['state'])->toBe($json->json());

        foreach ([$host, $player] as $someone) {
            expect($text)->not->toContain($someone->email)
                ->and($text)->not->toMatch('/"(user_id|account_id|email|ip)"/')
                ->and($text)->not->toContain('"id":'.$someone->id.',');
        }
    }

    // The name is the account's as it is now.
    $player->forceFill(['name' => 'Aygün Həsənova'])->save();
    $this->actingAs($host)->getJson(route('rooms.state', $room))
        ->assertJsonPath('players.1', ['id' => $row->id, 'name' => 'Aygün Həsənova', 'present' => true, 'host' => false]);
});

it('names no one who took their name out', function (): void {
    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $room = Rooms::open($host);
    $player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $row = Rooms::join($room, $player);
    $this->actingAs($host)->post(route('rooms.close', $room));

    $this->actingAs($player)
        ->delete(route('rooms.name.destroy', $room))
        ->assertRedirect(route('rooms.index'));

    expect($row->refresh()->user_id)->toBeNull();

    // The room is gone from the account's list and its page is not found.
    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('past', []));
    $this->get(route('rooms.show', $room))->assertNotFound();

    // The others see the row without a name.
    $this->actingAs($host)->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state.players.1', ['id' => $row->id, 'name' => null, 'present' => false, 'host' => false])
            ->where('state.me.player_id', RoomPlayer::query()->where('user_id', $host->id)->value('id')));
    expect((string) $this->get(route('rooms.show', $room))->getContent())->not->toContain('Aygün');
});

it('refuses the state to an account outside the room', function (): void {
    $room = Rooms::open();
    Rooms::join($room);
    $removed = User::factory()->player()->create();
    $removedRow = Rooms::join($room, $removed);
    $this->actingAs($room->host)->delete(route('rooms.members.destroy', [$room, $removedRow]));

    foreach ([User::factory()->player()->create(), $removed, User::factory()->teacherAndPlayer()->create()] as $outsider) {
        $this->actingAs($outsider)->getJson(route('rooms.state', $room))->assertNotFound();
        $this->get(route('rooms.show', $room))->assertNotFound();
    }

    // Without the player role too: the room is not found before the role is asked for.
    $this->actingAs(User::factory()->teacherAndParent()->create())->getJson(route('rooms.state', $room))->assertNotFound();

    auth()->logout();
    $this->getJson(route('rooms.state', $room))->assertUnauthorized();
});

it('limits the state to 120 a minute per account', function (): void {
    $room = Rooms::open();
    $this->actingAs($room->host);

    foreach (range(1, 120) as $_) {
        $this->getJson(route('rooms.state', $room))->assertOk();
    }

    $this->getJson(route('rooms.state', $room))->assertTooManyRequests();
});
