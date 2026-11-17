<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomMomentData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomRound;
use OutOfRangeException;

/**
 * A room game's timetable (docs/architecture.md §12 "The race", ADR 021
 * decision 5): a pure function of the game's facts — when round 0 opens,
 * each round's study and answer times, the reveal time, a round's early
 * close and the host's end — and a moment. It writes nothing, reads no
 * clock (the caller passes `RoomClock::nowMs()`) and nothing stores what
 * it says:
 *
 *     opens(0)        = started_ms
 *     answer_from(i)  = opens(i) + study_ms(i)
 *     bell(i)         = answer_from(i) + answer_ms(i)
 *     closes(i)       = closed_ms(i) ?? bell(i)
 *     opens(i + 1)    = closes(i) + reveal_ms
 *     over            = ended_ms ?? closes(last) + reveal_ms
 *
 * A moment before round 0 opens is its `countdown`; from `opens` it is
 * `study` (a round with study time), from `answer_from` `answer`, from
 * `closes` `reveal`, until the next round opens or the game is `over`.
 * Each bound belongs to the later phase: at the bell the round is already
 * revealed. Two guards keep the moments in order whatever a row holds: an
 * early close counts only inside its round's answer phase, and a game
 * ended after its own last reveal is over at that reveal's end.
 */
final readonly class RoomTimetable
{
    /**
     * @param  list<int>  $opens  when each round opens
     * @param  list<int>  $answerFrom  when each round's question opens
     * @param  list<int>  $bells
     * @param  list<int>  $closes
     * @param  int  $overMs  when the game is over
     */
    private function __construct(
        private array $opens,
        private array $answerFrom,
        private array $bells,
        private array $closes,
        private int $revealMs,
        private int $overMs,
    ) {}

    /**
     * The timetable of a game, from its rounds (loaded if they are not).
     */
    public static function of(RoomGame $game): self
    {
        $rounds = $game->rounds->sortBy('position')->values()
            ->map(static fn (RoomRound $round): array => [
                'study_ms' => $round->study_ms,
                'answer_ms' => $round->answer_ms,
                'closed_ms' => $round->closed_ms,
            ])
            ->all();

        return self::from($game->started_ms, $game->reveal_ms, $game->ended_ms, array_values($rounds));
    }

    /**
     * Where the game stands at `$nowMs`.
     */
    public static function at(RoomGame $game, int $nowMs): RoomMomentData
    {
        return self::of($game)->moment($nowMs);
    }

    /**
     * @param  list<array{study_ms: int, answer_ms: int, closed_ms: int|null}>  $rounds  in order
     */
    public static function from(int $startedMs, int $revealMs, ?int $endedMs, array $rounds): self
    {
        $opens = $answerFrom = $bells = $closes = [];
        $next = $startedMs;

        foreach ($rounds as $round) {
            $opens[] = $next;
            $from = $next + $round['study_ms'];
            $bell = $from + $round['answer_ms'];
            $answerFrom[] = $from;
            $bells[] = $bell;
            $close = $round['closed_ms'] === null ? $bell : min($bell, max($from, $round['closed_ms']));
            $closes[] = $close;
            $next = $close + $revealMs;
        }

        // `$next` is now the end of the last reveal, or the start when there is no round.
        return new self($opens, $answerFrom, $bells, $closes, $revealMs, $endedMs === null ? $next : min($endedMs, $next));
    }

    public function moment(int $nowMs): RoomMomentData
    {
        if ($this->opens === [] || $nowMs >= $this->overMs) {
            return RoomMomentData::over();
        }

        if ($nowMs < $this->opens[0]) {
            return new RoomMomentData(RoomPhase::Countdown, 0);
        }

        foreach (array_keys($this->opens) as $round) {
            $phase = match (true) {
                $nowMs < $this->answerFrom[$round] => RoomPhase::Study,
                $nowMs < $this->closes[$round] => RoomPhase::Answer,
                $nowMs < $this->next($round) => RoomPhase::Reveal,
                default => null,
            };

            if ($phase !== null) {
                return new RoomMomentData($phase, $round);
            }
        }

        return RoomMomentData::over();
    }

    /**
     * How many rounds the game has.
     */
    public function count(): int
    {
        return count($this->opens);
    }

    public function opens(int $round): int
    {
        return $this->opens[$round] ?? throw self::outOfRange($round);
    }

    public function answerFrom(int $round): int
    {
        return $this->answerFrom[$round] ?? throw self::outOfRange($round);
    }

    public function bell(int $round): int
    {
        return $this->bells[$round] ?? throw self::outOfRange($round);
    }

    /**
     * The early close, or the bell.
     */
    public function closes(int $round): int
    {
        return $this->closes[$round] ?? throw self::outOfRange($round);
    }

    /**
     * When the round's reveal ends: the next round opens, or after the
     * last round the timetable's own end.
     */
    public function next(int $round): int
    {
        return $this->closes($round) + $this->revealMs;
    }

    /**
     * When the game is over: the host's end, or the end of the last reveal.
     */
    public function overMs(): int
    {
        return $this->overMs;
    }

    private static function outOfRange(int $round): OutOfRangeException
    {
        return new OutOfRangeException("The game has no round {$round}.");
    }
}
