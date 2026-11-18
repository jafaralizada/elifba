<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The host's choice of the room's next game (docs/architecture.md §1
 * `rooms`, §12 "Games first", ADR 026 decision 8): a mechanic, a difficulty
 * and a number of rounds — the game the start screen of every device shows
 * before it starts, and the one `StartRoomGame` starts. A fact the host
 * wrote, through `OpenRoom` and `ChooseRoomGame` only; never text, never
 * anything about a person.
 *
 * Additive and nullable: rooms are live in production. A room opened before
 * this has no stored choice, and `Room::setup()` answers for it from its
 * latest game, else from the defaults (`RoomDefaults`); such rooms close by
 * themselves within `elifba.rooms.idle_hours`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('setup_mechanic', 32)->nullable();
            $table->unsignedTinyInteger('setup_difficulty')->nullable();
            $table->unsignedTinyInteger('setup_rounds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['setup_mechanic', 'setup_difficulty', 'setup_rounds']);
        });
    }
};
