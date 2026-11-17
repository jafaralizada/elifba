<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Route;

/*
 * The player area: adults' rooms (docs/architecture.md §1 "Accounts and
 * roles", §12 "The area and the switch", ADR 019). Every room route is here
 * and in no other group, and nothing here is a class, a student, a child or
 * a game of theirs. `rooms.enabled` comes first: while the instance has the
 * area switched off, every route below is a 404 to everyone, a visitor too.
 * An account without the player role is sent to take it, and back
 * (`RoomsArea::toThePlayerRole`).
 */
Route::middleware(['rooms.enabled', 'auth', 'role.chosen', 'verified', Role::middleware(Role::Player)])->group(function () {
    // «Otaqlar», the area's home; the next step of B2 gives it the rooms.
    Route::inertia('rooms', 'rooms/index')->name('rooms.index');
});
