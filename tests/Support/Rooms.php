<?php

namespace Tests\Support;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The adults' rooms area for feature tests (docs/architecture.md §12 "The
 * area and the switch", ADR 019). `Tests\TestCase` leaves the switch off,
 * as an instance has it; a test of the area turns it on first. Rooms are
 * opened and joined through their actions (`open()`, `join()`), as a
 * player's requests would, and a game is started through `StartRoomGame`
 * (`playing()`). A test reaches a moment of the game's timetable with
 * `$this->travelTo(Rooms::at($game, RoomPhase::Answer, 1))`, and reads a
 * round's hidden solution with `right()` — a device never can.
 */
final class Rooms
{
    /** Switches the rooms area on for the rest of the test. */
    public static function on(): void
    {
        Config::set('elifba.rooms.enabled', true);
    }

    /**
     * A room opened by `$host` (a new player when null) through `OpenRoom`:
     * a code, and the host as its first player unless `$plays` is false.
     */
    public static function open(?User $host = null, bool $plays = true): Room
    {
        return app(OpenRoom::class)->handle($host ?? User::factory()->player()->create(), new OpenRoomData($plays));
    }

    /**
     * `$account` (a new player when null) joins the room through `JoinRoom`.
     */
    public static function join(Room $room, ?User $account = null): RoomPlayer
    {
        return app(JoinRoom::class)->handle($room, $account ?? User::factory()->player()->create());
    }

    /**
     * A game of `recall_order` (or `$mechanic`) started in `$room` (a new
     * room when null) through `StartRoomGame`, with its rounds loaded;
     * round 0 opens after the countdown.
     */
    public static function playing(?Room $room = null, int $rounds = 5, int $difficulty = 1, RoomMechanic $mechanic = RoomMechanic::RecallOrder): RoomGame
    {
        return app(StartRoomGame::class)
            ->handle($room ?? self::open(), new RoomGameSetupData($mechanic, $difficulty, $rounds))
            ->load('rounds');
    }

    /**
     * The moment a phase of round `$index` begins by the game's timetable
     * as it is now (an early close moves it): the countdown's is a moment
     * before round 0 opens.
     */
    public static function at(RoomGame $game, RoomPhase $phase, int $index = 0): CarbonImmutable
    {
        $timetable = $game->refresh()->load('rounds')->timetable();

        return RoomClock::at(match ($phase) {
            RoomPhase::Countdown => $timetable->opens(0) - 1,
            RoomPhase::Study => $timetable->opens($index),
            RoomPhase::Answer => $timetable->answerFrom($index),
            RoomPhase::Reveal => $timetable->closes($index),
            RoomPhase::Over => $timetable->overMs(),
        });
    }

    /** Round `$index` of the game. */
    public static function round(RoomGame $game, int $index): RoomRound
    {
        return RoomRound::query()->where('room_game_id', $game->id)->where('position', $index)->firstOrFail();
    }

    /**
     * What a right answer picks: the round's solution, which only a test
     * reads.
     *
     * @return list<string>
     */
    public static function right(RoomRound $round): array
    {
        return $round->solutionData()->ids;
    }

    /**
     * A wrong answer of the right shape: for a sequence, the solution turned
     * by one place, which never reads like the row, since no item stands
     * beside its equal; for a set, one of its items swapped for an item of
     * the question that is not in it.
     *
     * @return list<string>
     */
    public static function wrong(RoomRound $round): array
    {
        $ids = self::right($round);

        if ($round->solutionData()->shape === RoomAnswerShape::Set) {
            $other = array_values(array_diff($round->askData()->ids(), $ids));

            return [...array_slice($ids, 1), $other[0]];
        }

        return [...array_slice($ids, 1), $ids[0]];
    }

    /**
     * The body a device posts for an answer, with a new event id unless
     * one is given.
     *
     * @param  list<string>  $picked
     * @return array{event_id: string, round_id: string, picked: list<string>}
     */
    public static function answer(RoomRound $round, array $picked, ?string $eventId = null): array
    {
        return ['event_id' => $eventId ?? Str::lower((string) Str::ulid()), 'round_id' => $round->id, 'picked' => $picked];
    }

    /**
     * Every route the switch closes (`rooms.enabled`), by name.
     *
     * @return array<string, RoutingRoute>
     */
    public static function routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('rooms.enabled', $route->gatherMiddleware(), true)) {
                $routes[$route->getName() ?? $route->methods()[0].' '.$route->uri()] = $route;
            }
        }

        return $routes;
    }

    /**
     * The URI of a room route with the given values for its parameters; a
     * route that takes one the test did not give fails loudly.
     *
     * @param  array<string, int|string>  $values
     */
    public static function uri(RoutingRoute $route, array $values = []): string
    {
        return '/'.ltrim((string) preg_replace_callback(
            '/\{(\w+)\??\}/',
            static fn (array $match): string => (string) ($values[$match[1]] ?? throw new RuntimeException("No value for {{$match[1]}} in {$route->uri()}.")),
            $route->uri(),
        ), '/');
    }
}
