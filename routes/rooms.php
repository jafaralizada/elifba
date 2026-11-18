<?php

use App\Enums\Role;
use App\Http\Controllers\Rooms\RoomAnswerController;
use App\Http\Controllers\Rooms\RoomCloseController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomGameController;
use App\Http\Controllers\Rooms\RoomJoinController;
use App\Http\Controllers\Rooms\RoomLeaveController;
use App\Http\Controllers\Rooms\RoomMemberController;
use App\Http\Controllers\Rooms\RoomNameController;
use App\Http\Controllers\Rooms\RoomPlaysController;
use App\Http\Controllers\Rooms\RoomScreenController;
use App\Http\Controllers\Rooms\RoomSetupController;
use App\Http\Controllers\Rooms\RoomStateController;
use App\Http\Controllers\Rooms\RoomTauntController;
use Illuminate\Support\Facades\Route;

/*
 * The player area: adults' games and their rooms (docs/architecture.md §1
 * "Accounts and roles", §12 "The area and the switch", "Rooms and joining",
 * "Games first", ADRs 019, 020 and 026). Every room route is here and in no other group, and nothing
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
 */
Route::middleware(['rooms.enabled', 'auth', 'role.chosen', 'verified', Role::middleware(Role::Player)])->group(function () {
    // «Oyunlar», the area's home: the games, the account's open rooms, the code field.
    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    // A game chosen: the room opens behind it, or the host's open room takes it (G2).
    Route::post('rooms', [RoomController::class, 'store'])
        ->middleware('throttle:rooms-open')
        ->name('rooms.store');
    // «Keçmiş oyunlar»: the rooms the account was in (G2).
    Route::get('rooms/past', [RoomController::class, 'past'])->name('rooms.past');

    // The entrance with a code, `/oyun` (G2): 10 tries of a code a minute per account, the three ways together.
    Route::middleware('throttle:room-join')->group(function () {
        Route::get('oyun', [RoomJoinController::class, 'entrance'])->name('rooms.entrance');
        Route::get('oyun/{code}', [RoomJoinController::class, 'show'])
            ->where('code', '[0-9]{6}')
            ->name('rooms.join');
        Route::post('oyun/{code}', [RoomJoinController::class, 'store'])
            ->where('code', '[0-9]{6}')
            ->name('rooms.join.store');
    });

    // The entrance's address before G2: permanent redirects, a query kept; nothing posts here.
    Route::get('otaq', [RoomJoinController::class, 'oldEntrance'])->name('rooms.entrance.old');
    Route::get('otaq/{code}', [RoomJoinController::class, 'oldJoin'])
        ->where('code', '[0-9]{6}')
        ->name('rooms.join.old');

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

    // The big screen: the host's own session on a laptop or a television (B4).
    Route::get('rooms/{room}/screen', RoomScreenController::class)
        ->whereUlid('room')
        ->can('screen', 'room')
        ->name('rooms.screen');

    // A present player's own answer to the round open now (B3).
    Route::post('rooms/{room}/answers', RoomAnswerController::class)
        ->whereUlid('room')
        ->middleware('throttle:room-answers')
        ->can('play', 'room')
        ->name('rooms.answers.store');

    // A present player's own taunt at another, for a point of their own (T2, ADR 029).
    Route::post('rooms/{room}/taunts', [RoomTauntController::class, 'store'])
        ->whereUlid('room')
        ->middleware('throttle:room-taunts')
        ->can('play', 'room')
        ->name('rooms.taunts.store');

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

    // The host's choice of the next game and «Mən də oynayıram», from the phone or the screen (G2).
    Route::put('rooms/{room}/setup', RoomSetupController::class)
        ->whereUlid('room')
        ->can('start', 'room')
        ->name('rooms.setup.update');
    Route::put('rooms/{room}/plays', RoomPlaysController::class)
        ->whereUlid('room')
        ->can('start', 'room')
        ->name('rooms.plays.update');

    // The host's switch «Atmacalar», from the phone or the screen, at any time in an open room (T2).
    Route::put('rooms/{room}/taunts', [RoomTauntController::class, 'update'])
        ->whereUlid('room')
        ->can('host', 'room')
        ->name('rooms.taunts.update');

    // A game: the room's choice started in an open room with none running, ended while it runs (B3, G2).
    Route::post('rooms/{room}/games', [RoomGameController::class, 'store'])
        ->whereUlid('room')
        ->can('start', 'room')
        ->name('rooms.games.store');
    Route::delete('rooms/{room}/game', [RoomGameController::class, 'destroy'])
        ->whereUlid('room')
        ->can('end', 'room')
        ->name('rooms.game.destroy');

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
