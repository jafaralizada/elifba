<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A room game, its rounds and the players' answers (docs/architecture.md §1
 * "The five tables of `Rooms`", §12 "The race", "Scoring and what is kept",
 * ADRs 021–023). They reference a room, each other and the room's player
 * rows only — never a class, a student, a child or a game of `Play` — and
 * no column holds a name or anything a person typed: an answer is the
 * round's own item ids.
 *
 * Moments that scoring reads are Unix milliseconds in `bigint` columns
 * (`*_ms`), because the engines differ in sub-second timestamps; durations
 * are whole milliseconds. Only facts are stored: the start, each answer, an
 * early close (`closed_ms`) and the host's end (`ended_ms`). The current
 * round, its phase, the ranking and whether a game is over are derived
 * (`RoomTimetable`, `RoomRanking`).
 *
 * Everything goes with its room (cascade): pruning, deleting a closed room
 * or the host's account. An answer also goes with its player's row, which
 * itself only loses its account when a player's account is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_games', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('mechanic');
            $table->unsignedTinyInteger('difficulty');
            $table->unsignedInteger('content_version');
            $table->integer('seed');
            $table->unsignedInteger('reveal_ms');
            $table->bigInteger('started_ms');
            $table->bigInteger('ended_ms')->nullable();
            $table->timestamp('created_at');

            $table->unique(['room_id', 'position']);
        });

        Schema::create('room_rounds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('room_game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->json('payload');
            $table->json('solution');
            $table->unsignedInteger('study_ms');
            $table->unsignedInteger('answer_ms');
            $table->bigInteger('closed_ms')->nullable();

            $table->unique(['room_game_id', 'position']);
        });

        Schema::create('room_answers', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->foreignUlid('room_round_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_player_id')->constrained()->cascadeOnDelete();
            $table->json('picked');
            $table->boolean('correct');
            $table->unsignedInteger('elapsed_ms');
            $table->unsignedSmallInteger('points');
            $table->timestamp('created_at');

            $table->unique(['room_round_id', 'room_player_id']);
            $table->index('room_player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_answers');
        Schema::dropIfExists('room_rounds');
        Schema::dropIfExists('room_games');
    }
};
