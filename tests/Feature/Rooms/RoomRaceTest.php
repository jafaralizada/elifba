<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * The race (docs/architecture.md §12 "The race", ADR 021): each player's
 * device posts its own answer and the server alone judges, times and
 * scores it; the timetable is derived and every phase serves only what it
 * may. Every state read here is read twice — `GET /rooms/{room}/state` and
 * the first props of `rooms/show` — and the two must be the same bytes,
 * since a page starts from its props; each must also go through the DTOs
 * unchanged, which refuse a key in the wrong phase.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', $this->host->id)->sole();
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
});

/**
 * The state as `$account` reads it, from the endpoint and from the page's
 * props, which must agree; `wire` is everything the account received.
 *
 * @return array{state: array<string, mixed>, wire: string}
 */
function raceRead(User $account, Room $room): array
{
    $json = test()->actingAs($account)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    $props = test()->get(route('rooms.show', $room))->assertOk()->viewData('page')['props'];
    $state = $json->json();
    unset($props['translations']);

    expect($props['state'])->toBe($state)
        ->and(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return ['state' => $state, 'wire' => $json->getContent().json_encode($props, JSON_UNESCAPED_UNICODE)];
}

/**
 * Posts `$picked` for `$round` as `$account`.
 *
 * @param  list<string>  $picked
 * @param  array<string, mixed>  $extra
 */
function raceAnswer(User $account, Room $room, RoomRound $round, array $picked, ?string $eventId = null, array $extra = []): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.answers.store', $room), [...Rooms::answer($round, $picked, $eventId), ...$extra]);
}

/**
 * The ids a round shows to study and asks with.
 *
 * @return array{study: list<string>, ask: list<string>}
 */
function raceIds(RoomRound $round): array
{
    return [
        'study' => array_map(static fn ($item): string => $item->id, $round->studyData()->items ?? []),
        'ask' => $round->askData()->ids(),
    ];
}

/**
 * Nothing in `$wire` tells right from wrong or the order to tap: no
 * solution, result, own answer or seed, and the solution's ids never as a
 * list in their order.
 */
function raceWithholds(string $wire, RoomRound $round): void
{
    expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|seed|picked|elapsed_ms)"/')
        ->and($wire)->not->toContain(json_encode(Rooms::right($round)))
        ->and($wire)->not->toContain(implode('', Rooms::right($round)));
}

function raceSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return [
        'rooms' => $rows('rooms'),
        'room_players' => $rows('room_players'),
        'room_games' => $rows('room_games'),
        'room_rounds' => $rows('room_rounds'),
        'room_answers' => $rows('room_answers'),
    ];
}

it('serves nothing of a round before it opens', function (): void {
    $game = Rooms::playing($this->room);
    $rounds = $game->rounds;

    // The countdown: round 0's number and moments, nothing of any round's content.
    foreach ([$this->host, $this->aygun] as $account) {
        ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);

        expect($state['game'])->toBe(['id' => $game->id, 'mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5, 'phase' => 'live'])
            ->and($state['round'])->toMatchArray([
                'id' => $rounds[0]->id, 'index' => 0, 'total' => 5, 'phase' => 'countdown',
                'opens_ms' => $game->started_ms, 'closes_ms' => null, 'next_ms' => null,
                'study' => null, 'ask' => null, 'reveal' => null, 'answered' => false, 'answered_count' => 0, 'present_count' => 2,
            ]);

        foreach ($rounds as $round) {
            foreach ([...raceIds($round)['study'], ...raceIds($round)['ask']] as $id) {
                expect($wire)->not->toContain($id);
            }
        }
    }

    // While round 0 is revealed, round 1 is still nothing; while round 1 is studied, round 2 is.
    foreach ([[RoomPhase::Reveal, 0, 1], [RoomPhase::Study, 1, 2], [RoomPhase::Reveal, 4, null]] as [$phase, $index, $next]) {
        $this->travelTo(Rooms::at($game, $phase, $index));
        ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

        expect($state['round']['index'])->toBe($index)->and($state['round']['phase'])->toBe($phase->value);

        foreach ($rounds->slice($next ?? 5) as $later) {
            foreach ([...raceIds($later)['study'], ...raceIds($later)['ask']] as $id) {
                expect($wire)->not->toContain($id);
            }
        }
    }
});

it('serves the study material only while it is studied', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $study = $round->studyData()?->toArray();

    $this->travelTo(Rooms::at($game, RoomPhase::Study));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'study', 'study' => $study, 'ask' => null]);

    $this->travelTo(RoomClock::at($game->timetable()->answerFrom(0) - 1));
    expect(raceRead($this->host, $this->room)['state']['round'])->toMatchArray(['phase' => 'study', 'study' => $study]);

    // From the question on, the material is gone, however often the page reloads.
    foreach ([RoomPhase::Answer, RoomPhase::Answer, RoomPhase::Reveal] as $phase) {
        $this->travelTo(Rooms::at($game, $phase));

        foreach ([$this->aygun, $this->host] as $account) {
            ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);

            expect($state['round']['study'])->toBeNull();

            foreach (raceIds($round)['study'] as $id) {
                expect($wire)->not->toContain($id);
            }
        }
    }
});

it('serves the question only from the answer phase', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $ask = $round->askData()->toArray();

    foreach ([RoomPhase::Countdown, RoomPhase::Study] as $phase) {
        $this->travelTo(Rooms::at($game, $phase));
        ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

        expect($state['round']['ask'])->toBeNull();

        foreach (raceIds($round)['ask'] as $id) {
            expect($wire)->not->toContain($id);
        }
    }

    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'answer', 'ask' => $ask, 'study' => null, 'reveal' => null]);

    // The question stays with its solution in the reveal.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'reveal', 'ask' => $ask]);
});

it('never serves the solution or a result before the round closes', function (): void {
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $turalRow = Rooms::join($this->room, $tural);
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    $before = raceRead($this->host, $this->room)['state']['ranking'];

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk();
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 1500));
    raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk();

    // Tural has not answered: the round is open, and nobody learns anything of it.
    foreach ([[$this->aygun, true], [$this->host, true], [$tural, false]] as [$account, $answered]) {
        ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);

        expect($state['round'])->toMatchArray(['phase' => 'answer', 'reveal' => null, 'closes_ms' => null, 'next_ms' => null, 'answered' => $answered, 'answered_count' => 2, 'present_count' => 3])
            ->and($state['ranking'])->toBe($before)
            ->and(array_column($state['ranking'], 'points'))->toBe([0, 0, 0]);
        raceWithholds($wire, $round);
    }

    // From the bell, all of it.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal));
    ['state' => $state] = raceRead($this->aygun, $this->room);
    $points = RoomPointsRule::points(true, 0, $round->answer_ms);

    expect($state['round']['phase'])->toBe('reveal')
        ->and($state['round']['closes_ms'])->toBe($game->timetable()->bell(0))
        ->and($state['round']['reveal'])->toBe([
            'solution' => Rooms::right($round),
            'word' => null,
            'mine' => ['picked' => Rooms::right($round), 'correct' => true, 'points' => $points, 'elapsed_ms' => 0],
            'results' => [
                ['player_id' => $this->hostRow->id, 'correct' => false, 'points' => 0],
                ['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $points],
            ],
        ])
        ->and($state['ranking'][0])->toBe(['player_id' => $this->aygunRow->id, 'points' => $points, 'place' => 1]);

    // Tural, who did not answer, has no result of his own.
    expect(raceRead($tural, $this->room)['state']['round']['reveal']['mine'])->toBeNull()
        ->and($turalRow->answers()->count())->toBe(0);
});

it('counts each player\'s answer once, whatever is sent twice', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    $event = Rooms::answer($round, Rooms::right($round))['event_id'];

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), $event)->assertExactJson(['status' => 'accepted', 'revision' => 2]);
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), $event)->assertExactJson(['status' => 'duplicate', 'revision' => 2]);
    // The same event with other items, in upper case, or later: the first stays.
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 2000));
    raceAnswer($this->aygun, $this->room, $round, Rooms::wrong($round), strtoupper($event))->assertJsonPath('status', 'duplicate');

    expect(RoomAnswer::query()->count())->toBe(1)
        ->and(RoomAnswer::query()->sole())->toMatchArray(['event_id' => $event, 'picked' => Rooms::right($round), 'correct' => true, 'elapsed_ms' => 0]);
});

it('refuses a second answer of the same player', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    raceAnswer($this->aygun, $this->room, $round, Rooms::wrong($round))->assertJsonPath('status', 'accepted');
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'answered', 'revision' => 2]);

    expect(RoomAnswer::query()->sole())->toMatchArray(['room_player_id' => $this->aygunRow->id, 'correct' => false, 'points' => 0]);
});

it('refuses an answer after the bell', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $timetable = $game->timetable();
    $before = raceSnapshot();

    // Before the question: late, whatever is picked.
    foreach ([Rooms::at($game, RoomPhase::Countdown), Rooms::at($game, RoomPhase::Study), RoomClock::at($timetable->answerFrom(0) - 1)] as $moment) {
        $this->travelTo($moment);
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'late', 'revision' => 2]);
        raceAnswer($this->aygun, $this->room, $round, ['zzzzzzzz'])->assertExactJson(['status' => 'late', 'revision' => 2]);
    }

    // At the bell and after it: late; the reveal is served from that moment.
    foreach ([$timetable->bell(0), $timetable->bell(0) + 1, $timetable->next(0) + 1] as $ms) {
        $this->travelTo(RoomClock::at($ms));
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'late');
    }

    expect(raceSnapshot())->toBe($before);

    // A moment before the bell is still in time.
    $this->travelTo(RoomClock::at($timetable->bell(1) - 1));
    $last = Rooms::round($game, 1);
    raceAnswer($this->aygun, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'accepted');
    expect(RoomAnswer::query()->sole()->elapsed_ms)->toBe($last->answer_ms - 1);

    // After an early close too: a late joiner finds the round closed.
    raceAnswer($this->host, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'accepted');
    $late = User::factory()->player()->create();
    Rooms::join($this->room, $late);
    raceAnswer($late, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'late');

    // …and stays closed if the server's clock steps back.
    $this->travelTo(RoomClock::at((int) $last->refresh()->closed_ms - 500));
    raceAnswer($late, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'late');

    // A round of another game, or of another room, is never open here.
    $other = Rooms::playing(Rooms::open($this->aygun), rounds: 5);
    $this->travelTo(Rooms::at($other, RoomPhase::Answer));
    raceAnswer($this->aygun, $this->room, Rooms::round($other, 0), Rooms::right(Rooms::round($other, 0)))->assertJsonPath('status', 'late');
    expect(RoomAnswer::query()->count())->toBe(2);
});

it('refuses items that are not the round\'s', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $next = Rooms::round($game, 1);
    $right = Rooms::right($round);
    $foreign = [
        'ids of another round' => Rooms::right($next),
        'the material\'s ids' => raceIds($round)['study'],
        'one too few' => array_slice($right, 1),
        'one too many' => [...$right, raceIds($next)['ask'][0]],
        'an unknown id' => [...array_slice($right, 1), 'zzzzzzzz'],
    ];
    $before = raceSnapshot();

    // Before the question, a foreign id is only late: nothing is learnt of the round.
    $this->travelTo(Rooms::at($game, RoomPhase::Study));
    foreach ($foreign as $picked) {
        raceAnswer($this->aygun, $this->room, $round, $picked)->assertJsonPath('status', 'late');
    }

    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    foreach ($foreign as $what => $picked) {
        raceAnswer($this->aygun, $this->room, $round, $picked)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['picked' => __('rooms.errors.picked')]);
    }

    // Malformed bodies are refused before anything is read.
    foreach ([['picked' => []], ['picked' => ['ABCDEFGH']], ['picked' => 'abcdefgh'], ['picked' => ['b' => $right[0]]], ['picked' => array_fill(0, 5000, 'zzzzzzzz')], ['round_id' => 'x'], ['event_id' => '7']] as $broken) {
        $this->actingAs($this->aygun)
            ->postJson(route('rooms.answers.store', $this->room), [...Rooms::answer($round, $right), ...$broken])
            ->assertUnprocessable();
    }

    expect(raceSnapshot())->toBe($before);

    // An id picked twice is the round's own and the right count: judged, and wrong.
    raceAnswer($this->aygun, $this->room, $round, [$right[0], ...array_slice($right, 0, -1)])->assertJsonPath('status', 'accepted');
    expect(RoomAnswer::query()->sole()->correct)->toBeFalse();
});

it('measures the time on the server\'s clock', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $from = $game->timetable()->answerFrom(0);

    // Whatever the device says of its own time is not read.
    $this->travelTo(RoomClock::at($from + 2345));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), extra: ['elapsed_ms' => 1, 'sent_ms' => $from, 'client_ms' => $from])
        ->assertExactJson(['status' => 'accepted', 'revision' => 2]);

    $this->travelTo(RoomClock::at($from + 9999));
    raceAnswer($this->host, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'accepted');

    $answers = RoomAnswer::query()->orderBy('id')->get();
    expect($answers->pluck('elapsed_ms')->all())->toBe([2345, 9999])
        ->and($answers->pluck('points')->all())->toBe([
            RoomPointsRule::points(true, 2345, $round->answer_ms),
            RoomPointsRule::points(true, 9999, $round->answer_ms),
        ])
        ->and(raceRead($this->aygun, $this->room)['state']['round']['reveal']['mine']['elapsed_ms'])->toBe(2345);
});

it('never says right or wrong in the receipt', function (): void {
    $tural = User::factory()->player()->create();
    Rooms::join($this->room, $tural);
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    $right = raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $wrong = raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($right->getContent())->toBe($wrong->getContent())
        ->and($right->json())->toBe(['status' => 'accepted', 'revision' => 3])
        ->and(RoomAnswer::query()->orderBy('id')->pluck('correct')->all())->toBe([true, false]);
});

it('closes the round when every present player has answered', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(RoomClock::at($game->timetable()->answerFrom(0) + 1000));

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'accepted', 'revision' => 2]);
    expect($round->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 500));
    $closedAt = RoomClock::nowMs();
    raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertExactJson(['status' => 'accepted', 'revision' => 3]);

    expect($round->refresh()->closed_ms)->toBe($closedAt);
    ['state' => $state] = raceRead($this->aygun, $this->room);
    expect($state['revision'])->toBe(3)
        ->and($state['round'])->toMatchArray(['index' => 0, 'phase' => 'reveal', 'closes_ms' => $closedAt, 'next_ms' => $closedAt + $game->reveal_ms, 'answered_count' => 2])
        ->and($game->refresh()->timetable()->opens(1))->toBe($closedAt + $game->reveal_ms);

    // A host who does not play is not waited for.
    $this->travelTo('2026-11-24 12:00:00');
    $watcher = User::factory()->player()->create();
    $room = Rooms::open($watcher, plays: false);
    [$a, $b] = [User::factory()->player()->create(), User::factory()->player()->create()];
    Rooms::join($room, $a);
    Rooms::join($room, $b);
    $other = Rooms::playing($room);
    $first = Rooms::round($other, 0);
    $this->travelTo(Rooms::at($other, RoomPhase::Answer));
    raceAnswer($a, $room, $first, Rooms::right($first));
    raceAnswer($b, $room, $first, Rooms::right($first));
    expect($first->refresh()->closed_ms)->toBe(RoomClock::nowMs());
});

it('does not wait for a player who left or was removed', function (): void {
    $tural = User::factory()->player()->create();
    $nigar = User::factory()->player()->create();
    Rooms::join($this->room, $tural);
    $nigarRow = Rooms::join($this->room, $nigar);
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    raceAnswer($this->host, $this->room, $round, Rooms::right($round));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round));

    // Tural leaves: Nigar has still to answer.
    $this->actingAs($tural)->post(route('rooms.leave', $this->room))->assertRedirect();
    expect($round->refresh()->closed_ms)->toBeNull();

    // The host removes Nigar: nobody left to wait for.
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 700));
    $this->actingAs($this->host)->delete(route('rooms.members.destroy', [$this->room, $nigarRow]))->assertRedirect();
    expect($round->refresh()->closed_ms)->toBe(RoomClock::nowMs());

    // Round 1: the host answers and Aygün leaves.
    $next = Rooms::round($game, 1);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    raceAnswer($this->host, $this->room, $next, Rooms::right($next));
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    expect($next->refresh()->closed_ms)->toBe(RoomClock::nowMs());

    // Round 2: the host, the only one left, leaves without answering — a round with no present player runs to its bell.
    $third = Rooms::round($game, 2);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 2));
    $this->actingAs($this->host)->post(route('rooms.leave', $this->room));
    expect($third->refresh()->closed_ms)->toBeNull();
});

it('closes the round when a player who answered comes back to it alone', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round));
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    $this->actingAs($this->host)->post(route('rooms.leave', $this->room));
    expect($round->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 900));
    $this->actingAs($this->aygun)->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));
    expect($round->refresh()->closed_ms)->toBe(RoomClock::nowMs());
});

it('lets a late joiner answer the open round', function (): void {
    $game = Rooms::playing($this->room);
    [$first, $second] = [Rooms::round($game, 0), Rooms::round($game, 1)];
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    raceAnswer($this->host, $this->room, $first, Rooms::right($first));
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first));

    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    raceAnswer($this->host, $this->room, $second, Rooms::right($second));

    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->actingAs($tural)->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));
    $turalRow = RoomPlayer::query()->where('user_id', $tural->id)->sole();

    ['state' => $state, 'wire' => $wire] = raceRead($tural, $this->room);
    expect($state['round'])->toMatchArray(['index' => 1, 'phase' => 'answer', 'ask' => $second->askData()->toArray(), 'answered' => false, 'answered_count' => 1, 'present_count' => 3])
        ->and(collect($state['ranking'])->firstWhere('player_id', $turalRow->id))->toBe(['player_id' => $turalRow->id, 'points' => 0, 'place' => null]);
    raceWithholds($wire, $second);

    // Counted from the moment of joining: Aygün's answer no longer closes the round.
    raceAnswer($this->aygun, $this->room, $second, Rooms::right($second));
    expect($second->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at($game->timetable()->answerFrom(1) + 4000));
    raceAnswer($tural, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'accepted');

    expect($turalRow->answers()->sole()->elapsed_ms)->toBe(4000)
        ->and($second->refresh()->closed_ms)->toBe(RoomClock::nowMs())
        // Nothing for the round before he came.
        ->and($turalRow->answers()->where('room_round_id', $first->id)->exists())->toBeFalse();
});

it('runs to its end with nobody watching', function (): void {
    $game = Rooms::playing($this->room, rounds: 5);
    $over = $game->timetable()->overMs();
    $before = raceSnapshot();

    // Just before the end, the last reveal; at the end, over — and asking writes nothing.
    $this->travelTo(RoomClock::at($over - 1));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['index' => 4, 'phase' => 'reveal']);

    $this->travelTo(RoomClock::at($over));
    ['state' => $state] = raceRead($this->aygun, $this->room);
    expect($state['game'])->toMatchArray(['id' => $game->id, 'phase' => 'over'])
        ->and($state['round'])->toBeNull()
        ->and(array_column($state['ranking'], 'place'))->toBe([null, null])
        ->and(raceSnapshot())->toBe($before);

    // The room is a lobby again: the host may start the next game.
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), ['mechanic' => 'recall_order', 'difficulty' => 2, 'rounds' => 5])->assertNoContent();
    expect(RoomGame::query()->where('room_id', $this->room->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1]);
});

it('ends when the host ends it', function (): void {
    $game = Rooms::playing($this->room);
    [$first, $second] = [Rooms::round($game, 0), Rooms::round($game, 1)];
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    raceAnswer($this->host, $this->room, $first, Rooms::wrong($first));
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first));

    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    raceAnswer($this->aygun, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'accepted');

    // Only the host, and only while it runs.
    $this->actingAs($this->aygun)->deleteJson(route('rooms.game.destroy', $this->room))->assertForbidden();
    $revision = $this->room->refresh()->revision;
    $this->actingAs($this->host)->deleteJson(route('rooms.game.destroy', $this->room))->assertNoContent();

    expect($game->refresh()->ended_ms)->toBe(RoomClock::nowMs())
        ->and($this->room->refresh()->revision)->toBe($revision + 1);

    // Over at once; the round it cut off gives nothing and is never revealed.
    ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);
    expect($state['game']['phase'])->toBe('over')
        ->and($state['round'])->toBeNull()
        ->and($state['ranking'][0])->toBe(['player_id' => $this->aygunRow->id, 'points' => $first->answers()->where('room_player_id', $this->aygunRow->id)->sole()->points, 'place' => 1]);
    raceWithholds($wire, $second);

    raceAnswer($this->host, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'late');
    $this->actingAs($this->host)->deleteJson(route('rooms.game.destroy', $this->room))->assertForbidden();
});

it('starts no second game while one is running', function (): void {
    $setup = ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5];

    // Only the host, and only with a level the room can play.
    $this->actingAs($this->aygun)->postJson(route('rooms.games.store', $this->room), $setup)->assertForbidden();
    foreach ([['rounds' => 4], ['rounds' => 21], ['difficulty' => 5], ['mechanic' => 'anagram'], ['mechanic' => 'find']] as $wrong) {
        $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), [...$setup, ...$wrong])->assertUnprocessable();
    }

    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), $setup)->assertNoContent();
    $game = $this->room->latestGame();
    expect($game)->toMatchArray(['position' => 0, 'difficulty' => 1, 'reveal_ms' => 5000, 'started_ms' => RoomClock::nowMs() + 3000])
        ->and($game?->rounds)->toHaveCount(5);

    // Refused while it runs, by the policy and by the action under the lock.
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), $setup)->assertForbidden();
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 4));
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), $setup)->assertForbidden();
    expect(fn () => app(StartRoomGame::class)->handle($this->room, new RoomGameSetupData(RoomMechanic::RecallOrder, 1, 5)))
        ->toThrow(AuthorizationException::class);
    expect(RoomGame::query()->count())->toBe(1);

    // Over: the next one starts.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), $setup)->assertNoContent();
    expect(RoomGame::query()->count())->toBe(2);

    // Never in a closed room.
    $this->travelTo(Rooms::at($this->room->latestGame(), RoomPhase::Over));
    $this->post(route('rooms.close', $this->room));
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room), $setup)->assertForbidden();
    expect(fn () => app(StartRoomGame::class)->handle($this->room, new RoomGameSetupData(RoomMechanic::RecallOrder, 1, 5)))
        ->toThrow(AuthorizationException::class);
});

it('offers the setup of a game to the host of an open room alone, as the server accepts it', function (): void {
    // Each mechanic the room can play with its difficulties, and the rounds' bounds; never the content's own list.
    $offered = ['mechanics' => [['mechanic' => 'recall_order', 'difficulties' => [1, 2, 3, 4]]], 'rounds' => ['min' => 5, 'max' => 20]];
    $this->actingAs($this->host)->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page->where('setup', $offered));
    $this->actingAs($this->aygun)->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page->where('setup', null));

    foreach ($offered['mechanics'][0]['difficulties'] as $difficulty) {
        expect(RoomGameSetupData::validate(['mechanic' => 'recall_order', 'difficulty' => $difficulty, 'rounds' => 5])->difficulty)->toBe($difficulty);
    }

    $this->actingAs($this->host)->post(route('rooms.close', $this->room));
    $this->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page->where('setup', null));
});

it('ends the game when the room closes', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'accepted');

    $this->actingAs($this->host)->post(route('rooms.close', $this->room))->assertRedirect(route('rooms.show', $this->room));

    expect($game->refresh()->ended_ms)->toBe(RoomClock::nowMs());

    // The closed room's page: the game is over, its open round never counted nor revealed.
    $props = $this->actingAs($this->aygun)->get(route('rooms.show', $this->room))->assertOk()->viewData('page')['props'];
    unset($props['translations']);
    expect($props['state']['game']['phase'])->toBe('over')
        ->and($props['state']['round'])->toBeNull()
        ->and(array_column($props['state']['ranking'], 'points'))->toBe([0, 0]);
    raceWithholds((string) json_encode($props), $round);

    // Nobody answers in a closed room.
    raceAnswer($this->host, $this->room, $round, Rooms::right($round))->assertForbidden();
    expect(RoomAnswer::query()->count())->toBe(1);
});

it('takes the player from the session, never from the body', function (): void {
    $watcher = User::factory()->player()->create();
    $room = Rooms::open($watcher, plays: false);
    $playerRow = Rooms::join($room, $this->aygun);
    $game = Rooms::playing($room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    // A host who does not play has no row to answer with.
    raceAnswer($watcher, $room, $round, Rooms::right($round))->assertForbidden();

    // A body that names a player is answered as the session's account.
    raceAnswer($this->aygun, $room, $round, Rooms::wrong($round), extra: ['player_id' => $playerRow->id, 'room_player_id' => $this->hostRow->id, 'user_id' => $watcher->id])
        ->assertJsonPath('status', 'accepted');

    expect(RoomAnswer::query()->sole()->room_player_id)->toBe($playerRow->id);
});

it('limits answers to 30 a minute per account', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Study));

    foreach (range(1, 30) as $_) {
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk();
    }

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertTooManyRequests();
});
