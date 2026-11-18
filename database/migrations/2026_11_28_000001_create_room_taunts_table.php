<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Taunts (docs/architecture.md §1 `rooms`, `room_taunts`, §12 "Taunts
 * (T2)", ADR 029 decision 14): the host's switch for them, on by default,
 * and a row for every taunt a player threw at another during a game — the
 * game, the thrower, the target, a key of `content/rooms/taunts.json` and
 * the server's moment. Never text, never a name, never a device.
 *
 * The thrower's row is what the cost needs (`RoomPointsRule::TAUNT_COST`,
 * counted by `RoomRanking`), so a taunt goes with it and with its game
 * (cascade); the target is only what the target's phone needed, and
 * `PruneOldRooms` empties it a day after the throw
 * (`elifba.rooms.taunts.forget_target_hours`).
 *
 * Additive: rooms are live in production, and an open room takes the
 * default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('taunts')->default(true);
        });

        Schema::create('room_taunts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('event_id')->unique();
            $table->foreignUlid('room_game_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_player_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('target_player_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->string('taunt', 32);
            $table->bigInteger('sent_ms');

            $table->index(['room_game_id', 'room_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_taunts');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('taunts');
        });
    }
};
