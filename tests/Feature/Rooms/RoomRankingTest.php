<?php

use App\Domain\Rooms\Data\RoomRankedPlayerData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomRanking;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * A room's ranking (docs/architecture.md §12 "Scoring and what is kept",
 * ADR 022 decision 4): derived from the answers' points over closed rounds
 * only, dense places with no tie-breaker, names in Azerbaijani order inside
 * a place with a nameless player last, players without points after the
 * places, a removed player nowhere. The page labels the places from its
 * props.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
});

/**
 * A room hosted by an account named `$names[0]` with a row for every name
 * (null: a row without an account), and a game of `$rounds` rounds.
 *
 * @param  list<string|null>  $names
 * @return array{room: Room, game: RoomGame, rows: list<RoomPlayer>}
 */
function roomRankingRoom(array $names, int $rounds = 3): array
{
    $host = User::factory()->player()->create(['name' => $names[0]]);
    $room = Room::factory()->for($host, 'host')->create();
    $rows = [];

    foreach ($names as $position => $name) {
        $rows[] = RoomPlayer::factory()->for($room)->create([
            'user_id' => $position === 0 ? $host->id : ($name === null ? null : User::factory()->player()->create(['name' => $name])->id),
            'joined_at' => now()->addSeconds($position),
        ]);
    }

    return ['room' => $room, 'game' => RoomGame::factory()->for($room)->withRounds($rounds)->create(), 'rows' => $rows];
}

/**
 * Gives each row its points in round `$index`: 0 is a wrong answer.
 *
 * @param  array<int, int>  $points  by the row's position
 * @param  list<RoomPlayer>  $rows
 */
function roomRankingAnswers(RoomGame $game, int $index, array $rows, array $points): void
{
    foreach ($points as $position => $value) {
        RoomAnswer::factory()->for(Rooms::round($game, $index), 'round')->by($rows[$position])->create([
            'correct' => $value > 0,
            'points' => $value,
        ]);
    }
}

/**
 * @param  list<RoomRankedPlayerData>  $ranking
 * @param  list<RoomPlayer>  $rows
 * @return list<array{0: int, 1: int, 2: int|null}> each row as [its position in `$rows`, points, place]
 */
function roomRankingRows(array $ranking, array $rows): array
{
    $positions = array_flip(array_map(static fn (RoomPlayer $row): string => $row->id, $rows));

    return array_map(static fn (RoomRankedPlayerData $row): array => [$positions[$row->player_id], $row->points, $row->place], $ranking);
}

it('sums only closed rounds', function (): void {
    ['room' => $room, 'game' => $game, 'rows' => $rows] = roomRankingRoom(['Rəşad', 'Aygün']);
    roomRankingAnswers($game, 0, $rows, [20, 12]);
    roomRankingAnswers($game, 1, $rows, [0, 18]);
    $timetable = $game->timetable();

    // Round 1 is open: only round 0 counts, whatever is already stored for round 1.
    $this->travelTo(RoomClock::at($timetable->bell(1) - 1));
    expect(roomRankingRows(RoomRanking::ofGame($game, RoomClock::nowMs()), $rows))->toBe([[0, 20, 1], [1, 12, 2]]);
    $this->actingAs($room->host)->getJson(route('rooms.state', $room))
        ->assertJsonPath('round.phase', 'answer')
        ->assertJsonPath('ranking', [
            ['player_id' => $rows[0]->id, 'points' => 20, 'place' => 1],
            ['player_id' => $rows[1]->id, 'points' => 12, 'place' => 2],
        ]);

    // At its bell it is closed, and counts.
    $this->travelTo(RoomClock::at($timetable->bell(1)));
    expect(roomRankingRows(RoomRanking::ofGame($game, RoomClock::nowMs()), $rows))->toBe([[1, 30, 1], [0, 20, 2]]);

    // The host ends the game in round 2's answer phase: round 2 never closed and gives nothing.
    roomRankingAnswers($game, 2, $rows, [20, 0]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 2));
    $this->actingAs($room->host)->deleteJson(route('rooms.game.destroy', $room))->assertNoContent();
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 60_000));
    expect(roomRankingRows(RoomRanking::ofGame($game->refresh(), RoomClock::nowMs()), $rows))->toBe([[1, 30, 1], [0, 20, 2]]);

    // A round whose close is the game's end was never revealed: it does not count either.
    $cut = Rooms::playing($room, rounds: 5);
    roomRankingAnswers($cut, 0, $rows, [20, 20]);
    $this->travelTo(RoomClock::at($cut->timetable()->bell(0)));
    $this->actingAs($room->host)->deleteJson(route('rooms.game.destroy', $room))->assertNoContent();
    expect(RoomRanking::closedRounds($cut->refresh(), RoomClock::nowMs() + 60_000))->toBe([]);

    // The room's standing adds up its games, each over its closed rounds.
    $next = Rooms::playing($room, rounds: 5);
    roomRankingAnswers($next, 0, $rows, [15, 0]);
    $this->travelTo(Rooms::at($next, RoomPhase::Over));
    expect(roomRankingRows(RoomRanking::ofRoom($room, RoomClock::nowMs()), $rows))->toBe([[0, 35, 1], [1, 30, 2]]);
    $this->actingAs($room->host)->getJson(route('rooms.state', $room))
        ->assertJsonPath('game.phase', 'over')
        ->assertJsonPath('ranking.0', ['player_id' => $rows[0]->id, 'points' => 35, 'place' => 1]);
});

it('shares a place on a tie and skips none', function (): void {
    ['room' => $room, 'game' => $game, 'rows' => $rows] = roomRankingRoom(['Rəşad', 'Aygün', 'Tural', 'Nigar', 'Leyla'], rounds: 2);
    roomRankingAnswers($game, 0, $rows, [14, 20, 20, 0]);
    roomRankingAnswers($game, 1, $rows, [20, 14, 0]);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    // 34, 34, 20 → 1, 1, 2; a wrong answer and no answer are no points and no place.
    expect(roomRankingRows(RoomRanking::ofGame($game, RoomClock::nowMs()), $rows))->toBe([
        [1, 34, 1], [0, 34, 1], [2, 20, 2], [4, 0, null], [3, 0, null],
    ]);

    // The page labels the places, never TypeScript.
    $this->actingAs($room->host)->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('place_labels', fn ($labels): bool => count($labels) === 20 && $labels[0] === '1-ci' && $labels[1] === '2-ci' && $labels[2] === '3-cü'));
    $this->actingAs($room->host)->put(route('locale.update'), ['locale' => 'en']);
    $this->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page->where('place_labels.0', '1st')->where('place_labels.1', '2nd')->where('place_labels.10', '11th'));
});

it('orders names inside a place by the Azerbaijani alphabet', function (): void {
    // Byte order would put «Çingiz», «Əli» and «İlkin» after every Latin letter.
    ['game' => $game, 'rows' => $rows] = roomRankingRoom(['İlkin', 'Əli', null, 'Elnur', 'Çingiz', 'Ilqar', 'Cavid', 'Zaur', 'Ülvi', null], rounds: 1);
    roomRankingAnswers($game, 0, $rows, [15, 15, 15, 15, 15, 15, 15, 12]);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    expect(roomRankingRows(RoomRanking::ofGame($game, RoomClock::nowMs()), $rows))->toBe([
        [6, 15, 1], // Cavid
        [4, 15, 1], // Çingiz
        [3, 15, 1], // Elnur
        [1, 15, 1], // Əli
        [5, 15, 1], // Ilqar
        [0, 15, 1], // İlkin
        [2, 15, 1], // a nameless player, last in the place
        [7, 12, 2], // Zaur
        [8, 0, null], // Ülvi
        [9, 0, null], // nameless, last
    ]);
});

it('leaves out a removed player', function (): void {
    ['room' => $room, 'game' => $game, 'rows' => $rows] = roomRankingRoom(['Rəşad', 'Aygün', 'Tural'], rounds: 1);
    roomRankingAnswers($game, 0, $rows, [12, 15, 20]);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    $this->actingAs($room->host)->delete(route('rooms.members.destroy', [$room, $rows[2]]))->assertRedirect();

    // The top score goes with the removed player; nobody else moves down for it.
    expect(roomRankingRows(RoomRanking::ofGame($game, RoomClock::nowMs()), $rows))->toBe([[1, 15, 1], [0, 12, 2]]);
    $this->getJson(route('rooms.state', $room))
        ->assertJsonCount(2, 'ranking')
        ->assertJsonMissing(['player_id' => $rows[2]->id]);
});

it('keeps a nameless player\'s points', function (): void {
    ['room' => $room, 'game' => $game, 'rows' => $rows] = roomRankingRoom(['Rəşad', 'Aygün', 'Tural'], rounds: 1);
    roomRankingAnswers($game, 0, $rows, [12, 15, 15]);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $aygun = User::query()->findOrFail($rows[1]->user_id);

    $this->actingAs($room->host)->post(route('rooms.close', $room));
    $this->actingAs($aygun)->delete(route('rooms.name.destroy', $room))->assertRedirect(route('rooms.index'));

    // «Oyunçu» keeps 15 and the place, last in it; the others do not move.
    expect(roomRankingRows(RoomRanking::ofRoom($room, RoomClock::nowMs()), $rows))->toBe([[2, 15, 1], [1, 15, 1], [0, 12, 2]]);
    $this->actingAs($room->host)->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state.ranking.1', ['player_id' => $rows[1]->id, 'points' => 15, 'place' => 1])
            ->where('state.players.1.name', null));
});
