<?php

use App\Actions\Cache\PruneExpiredCache;
use App\Domain\Classroom\Actions\PruneArchived;
use App\Domain\Play\Actions\AbandonStaleGames;
use App\Domain\Play\Actions\PruneGuestBoards;
use App\Domain\Play\Actions\PruneOldGames;
use App\Domain\Rooms\Actions\CloseIdleRooms;
use App\Domain\Rooms\Actions\PruneOldRooms;
use App\Domain\Rooms\Actions\SweepRoomVoices;
use App\Support\RoomsArea;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('classroom:prune-archived', function (PruneArchived $prune) {
    $deleted = $prune->handle();

    $this->info("Deleted {$deleted['classes']} archived classes and {$deleted['students']} archived students.");
})->purpose('Delete classes and students archived longer than elifba.retention_days');

Artisan::command('play:abandon-stale', function (AbandonStaleGames $abandon) {
    $this->info("Abandoned {$abandon->handle()} games with no activity.");
})->purpose('Abandon live and paused games idle longer than elifba.abandon_after_hours');

Artisan::command('play:prune-old-games', function (PruneOldGames $prune) {
    $this->info("Deleted {$prune->handle()} games with their rounds and answers.");
})->purpose('Delete games started longer than elifba.retention_days ago');

Artisan::command('play:prune-guest-boards', function (PruneGuestBoards $prune) {
    $this->info("Deleted {$prune->handle()} closed, expired or never-claimed guest boards.");
})->purpose('Delete guest boards that can no longer be used');

Artisan::command('rooms:close-idle', function (CloseIdleRooms $close) {
    $this->info("Closed {$close->handle()} rooms with no activity.");
})->purpose('Close rooms idle longer than elifba.rooms.idle_hours');

Artisan::command('rooms:prune', function (PruneOldRooms $prune) {
    $this->info("Deleted {$prune->handle()} rooms with their players.");
})->purpose('Delete rooms created longer than elifba.retention_days ago');

Artisan::command('cache:prune-expired', function (PruneExpiredCache $prune) {
    $this->info("Deleted {$prune->handle()} expired cache rows.");
})->purpose('Delete the database cache\'s expired rows, a rate limiter\'s counts among them');

Artisan::command('rooms:voice-sweep', function (SweepRoomVoices $sweep) {
    $this->info("Closed {$sweep->handle(RoomsArea::voice())} voice rooms.");
})->purpose('Make the voice server match the rooms: close the voice rooms that should not be open');

// One `scheduler` process runs these (docs/architecture.md §1, §10). Nothing
// is scheduled for a round or a game of a room: its timetable is derived (§12).
Schedule::command('classroom:prune-archived')->daily();
Schedule::command('play:abandon-stale')->hourly();
Schedule::command('play:prune-old-games')->daily();
Schedule::command('play:prune-guest-boards')->hourly();
Schedule::command('rooms:close-idle')->hourly();
Schedule::command('rooms:prune')->daily();
// A limiter's count of an account's requests (the voice's tickets among them) lives a minute, not until its next one.
Schedule::command('cache:prune-expired')->everyMinute();
// Every 15 seconds, and only while a voice server is named (§12 "Live voice", ADR 031 decision 23).
SweepRoomVoices::schedule(app(Scheduler::class));
