<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The adults' rooms area for feature tests (docs/architecture.md §12 "The
 * area and the switch", ADR 019). `Tests\TestCase` leaves the switch off,
 * as an instance has it; a test of the area turns it on first. The next
 * step of B2 adds the rooms (`open()`, `playing()`).
 */
final class Rooms
{
    /** Switches the rooms area on for the rest of the test. */
    public static function on(): void
    {
        Config::set('elifba.rooms.enabled', true);
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
