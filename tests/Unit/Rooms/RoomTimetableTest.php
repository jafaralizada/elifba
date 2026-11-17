<?php

use App\Domain\Rooms\Data\RoomMomentData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomTimetable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/*
 * A room game's timetable (docs/architecture.md §12 "The race", ADR 021
 * decision 5): a pure function of the start, each round's study and answer
 * times, the reveal time and the two facts — an early close, the host's
 * end — and a moment. Nothing here touches the database or the clock.
 */

const TIMETABLE_START = 1_795_000_000_000;

/**
 * A game as the timetable reads it, without a database: rounds of
 * [study_ms, answer_ms, closed_ms].
 *
 * @param  list<array{int, int, int|null}>  $rounds
 */
function timetableGame(array $rounds, ?int $endedMs = null, int $revealMs = 5_000): RoomGame
{
    $game = (new RoomGame)->forceFill(['started_ms' => TIMETABLE_START, 'reveal_ms' => $revealMs, 'ended_ms' => $endedMs]);
    $models = [];

    foreach ($rounds as $position => [$study, $answer, $closed]) {
        $models[] = (new RoomRound)->forceFill(['position' => $position, 'study_ms' => $study, 'answer_ms' => $answer, 'closed_ms' => $closed]);
    }

    // Out of order on purpose: the timetable goes by position.
    return $game->setRelation('rounds', new Collection(array_reverse($models)));
}

/**
 * @param  list<array{int, RoomPhase, int|null}>  $expected  [ms after the start, phase, round]
 */
function expectMoments(RoomGame $game, array $expected): void
{
    foreach ($expected as [$after, $phase, $round]) {
        $moment = RoomTimetable::at($game, TIMETABLE_START + $after);

        expect([$moment->phase, $moment->round])->toBe([$phase, $round], "at start + {$after} ms");
    }
}

it('derives every phase from the start, the durations and the facts', function (): void {
    $game = timetableGame([[3_000, 10_000, null], [4_000, 12_000, null], [2_000, 8_000, null]]);
    $timetable = $game->timetable();

    expect($timetable->count())->toBe(3)
        ->and([$timetable->opens(0), $timetable->answerFrom(0), $timetable->bell(0), $timetable->closes(0), $timetable->next(0)])
        ->toBe(array_map(static fn (int $ms): int => TIMETABLE_START + $ms, [0, 3_000, 13_000, 13_000, 18_000]))
        ->and([$timetable->opens(1), $timetable->answerFrom(1), $timetable->bell(1), $timetable->next(1)])
        ->toBe(array_map(static fn (int $ms): int => TIMETABLE_START + $ms, [18_000, 22_000, 34_000, 39_000]))
        ->and([$timetable->opens(2), $timetable->answerFrom(2), $timetable->bell(2), $timetable->overMs()])
        ->toBe(array_map(static fn (int $ms): int => TIMETABLE_START + $ms, [39_000, 41_000, 49_000, 54_000]));

    // Each bound belongs to the later phase: at the bell the round is revealed.
    expectMoments($game, [
        [-3_000, RoomPhase::Countdown, 0],
        [-1, RoomPhase::Countdown, 0],
        [0, RoomPhase::Study, 0],
        [2_999, RoomPhase::Study, 0],
        [3_000, RoomPhase::Answer, 0],
        [12_999, RoomPhase::Answer, 0],
        [13_000, RoomPhase::Reveal, 0],
        [17_999, RoomPhase::Reveal, 0],
        [18_000, RoomPhase::Study, 1],
        [22_000, RoomPhase::Answer, 1],
        [34_000, RoomPhase::Reveal, 1],
        [39_000, RoomPhase::Study, 2],
        [41_000, RoomPhase::Answer, 2],
        [49_000, RoomPhase::Reveal, 2],
    ]);

    // A round with nothing to study opens on its question.
    expectMoments(timetableGame([[0, 10_000, null], [0, 10_000, null]]), [
        [-1, RoomPhase::Countdown, 0],
        [0, RoomPhase::Answer, 0],
        [10_000, RoomPhase::Reveal, 0],
        [15_000, RoomPhase::Answer, 1],
    ]);

    // The same facts give the same moments, whatever asks.
    expect(RoomTimetable::at($game, TIMETABLE_START + 5_000))->toEqual(new RoomMomentData(RoomPhase::Answer, 0))
        ->and(RoomTimetable::at($game, TIMETABLE_START + 5_000)->answers(0))->toBeTrue()
        ->and(RoomTimetable::at($game, TIMETABLE_START + 5_000)->answers(1))->toBeFalse()
        ->and(fn () => $timetable->opens(3))->toThrow(OutOfRangeException::class);
});

it('opens the next round a reveal after an early close', function (): void {
    $game = timetableGame([[3_000, 10_000, TIMETABLE_START + 7_000], [4_000, 12_000, null]]);
    $timetable = $game->timetable();

    expect($timetable->bell(0))->toBe(TIMETABLE_START + 13_000)
        ->and($timetable->closes(0))->toBe(TIMETABLE_START + 7_000)
        ->and($timetable->opens(1))->toBe(TIMETABLE_START + 12_000)
        ->and($timetable->overMs())->toBe(TIMETABLE_START + 33_000);

    expectMoments($game, [
        [6_999, RoomPhase::Answer, 0],
        [7_000, RoomPhase::Reveal, 0],
        [11_999, RoomPhase::Reveal, 0],
        [12_000, RoomPhase::Study, 1],
        [13_000, RoomPhase::Study, 1],
        [16_000, RoomPhase::Answer, 1],
        [28_000, RoomPhase::Reveal, 1],
        [33_000, RoomPhase::Over, null],
    ]);

    // A close is a fact of the answer phase: one outside it is held to it.
    $early = timetableGame([[3_000, 10_000, TIMETABLE_START + 1_000]])->timetable();
    $late = timetableGame([[3_000, 10_000, TIMETABLE_START + 99_000]])->timetable();

    expect($early->closes(0))->toBe(TIMETABLE_START + 3_000)
        ->and($late->closes(0))->toBe(TIMETABLE_START + 13_000);
});

it('is over at the host\'s end', function (): void {
    $rounds = [[3_000, 10_000, null], [4_000, 12_000, null]];
    $ended = timetableGame($rounds, endedMs: TIMETABLE_START + 20_000);

    expect($ended->timetable()->overMs())->toBe(TIMETABLE_START + 20_000);
    expectMoments($ended, [
        [19_999, RoomPhase::Study, 1],
        [20_000, RoomPhase::Over, null],
        [22_000, RoomPhase::Over, null],
    ]);

    // Ended in the countdown: no round ever opens.
    expectMoments(timetableGame($rounds, endedMs: TIMETABLE_START - 1_000), [
        [-1_001, RoomPhase::Countdown, 0],
        [-1_000, RoomPhase::Over, null],
        [0, RoomPhase::Over, null],
    ]);

    // An end after the timetable's own end changes nothing.
    expect(timetableGame($rounds, endedMs: TIMETABLE_START + 99_000)->timetable()->overMs())->toBe(TIMETABLE_START + 39_000);
});

it('is over by itself after the last reveal', function (): void {
    $game = timetableGame([[3_000, 10_000, null], [4_000, 12_000, TIMETABLE_START + 25_000]]);

    expect($game->timetable()->overMs())->toBe(TIMETABLE_START + 30_000)
        ->and($game->timetable()->next(1))->toBe(TIMETABLE_START + 30_000);

    expectMoments($game, [
        [25_000, RoomPhase::Reveal, 1],
        [29_999, RoomPhase::Reveal, 1],
        [30_000, RoomPhase::Over, null],
        [86_400_000, RoomPhase::Over, null],
    ]);

    expect(RoomTimetable::at($game, TIMETABLE_START + 30_000)->isOver())->toBeTrue()
        ->and(RoomTimetable::at(timetableGame([]), TIMETABLE_START - 1)->isOver())->toBeTrue();
});

it('takes now only from the room clock, which follows travelTo to the millisecond', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 18:00:00.250', 'UTC'));

    $now = RoomClock::nowMs();

    expect($now)->toBe(1_795_543_200_250)
        ->and(RoomClock::at($now)->toIso8601ZuluString('millisecond'))->toBe('2026-11-24T18:00:00.250Z');

    $this->travel(1_750)->milliseconds();

    expect(RoomClock::nowMs())->toBe($now + 1_750);
});
