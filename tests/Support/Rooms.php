<?php

namespace Tests\Support;

use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The adults' rooms area for feature tests (docs/architecture.md §12 "The
 * area and the switch", ADR 019). `Tests\TestCase` leaves the switch off,
 * as an instance has it; a test of the area turns it on first. Rooms are
 * opened and joined through their actions (`open()`, `join()`), as a
 * player's requests would; B3 adds a room with a game (`playing()`).
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
