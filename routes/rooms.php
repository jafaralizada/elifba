<?php

use App\Enums\Role;
use App\Http\Controllers\Rooms\RoomCloseController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomJoinController;
use App\Http\Controllers\Rooms\RoomLeaveController;
use App\Http\Controllers\Rooms\RoomMemberController;
use App\Http\Controllers\Rooms\RoomNameController;
use App\Http\Controllers\Rooms\RoomStateController;
use Illuminate\Support\Facades\Route;

/*
 * The player area: adults' rooms (docs/architecture.md §1 "Accounts and
 * roles", §12 "The area and the switch", "Rooms and joining", ADR 019,
 * ADR 020). Every room route is here and in no other group, and nothing
 * here is a class, a student, a child or a game of theirs. `rooms.enabled`
 * comes first: while the instance has the area switched off, every route
 * below is a 404 to everyone, a visitor too. An account without the player
 * role is sent to take it, and back (`RoomsArea::toThePlayerRole`).
 *
 * Isolation as in routes/classroom.php: `{room}` binds only a room the
 * current account hosts or has a row in that the host did not remove
 * (`Room::resolveRouteBinding`, 404 before any controller code), `{member}`
 * only a row of that room (`scopeBindings()`), and every such route passes
 * `RoomPolicy` (`->can()`). Both are ULIDs; a name never goes into a URL. A
 * new route with `{room}` or `{member}` must be added to
 * tests/Feature/Classroom/TeacherIsolationTest.php, which fails otherwise.
 * B3 adds the game and the answers; B4 the screen.
 */
Route::middleware(['rooms.enabled', 'auth', 'role.chosen', 'verified', Role::middleware(Role::Player)])->group(function () {
    // «Otaqlar», the area's home: the account's rooms, the code field, «Otaq aç».
    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('rooms', [RoomController::class, 'store'])
        ->middleware('throttle:rooms-open')
        ->name('rooms.store');

    // The entrance with a code: 10 a minute per account, all three together.
    Route::middleware('throttle:room-join')->group(function () {
        Route::get('otaq', [RoomJoinController::class, 'entrance'])->name('rooms.entrance');
        Route::get('otaq/{code}', [RoomJoinController::class, 'show'])
            ->where('code', '[0-9]{6}')
            ->name('rooms.join');
        Route::post('otaq/{code}', [RoomJoinController::class, 'store'])
            ->where('code', '[0-9]{6}')
            ->name('rooms.join.store');
    });

    Route::get('rooms/{room}', [RoomController::class, 'show'])
        ->whereUlid('room')
        ->can('view', 'room')
        ->name('rooms.show');
    Route::get('rooms/{room}/state', RoomStateController::class)
        ->whereUlid('room')
        ->middleware('throttle:room-state')
        ->can('watch', 'room')
        ->name('rooms.state');
    Route::post('rooms/{room}/leave', RoomLeaveController::class)
        ->whereUlid('room')
        ->can('play', 'room')
        ->name('rooms.leave');

    // The host's tools.
    Route::patch('rooms/{room}', [RoomController::class, 'update'])
        ->whereUlid('room')
        ->can('host', 'room')
        ->name('rooms.update');
    Route::delete('rooms/{room}/members/{member}', [RoomMemberController::class, 'destroy'])
        ->whereUlid(['room', 'member'])
        ->scopeBindings()
        ->can('remove', ['room', 'member'])
        ->name('rooms.members.destroy');
    Route::post('rooms/{room}/close', RoomCloseController::class)
        ->whereUlid('room')
        ->can('host', 'room')
        ->name('rooms.close');

    // A closed room: the host deletes it, a player takes their name out.
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])
        ->whereUlid('room')
        ->can('delete', 'room')
        ->name('rooms.destroy');
    Route::delete('rooms/{room}/name', [RoomNameController::class, 'destroy'])
        ->whereUlid('room')
        ->can('forget', 'room')
        ->name('rooms.name.destroy');
});
