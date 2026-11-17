<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Adults' rooms (docs/architecture.md §1 "The five tables of `Rooms`", §12
 * "Rooms and joining", ADR 020): a room an account opened, and the accounts
 * in it. They reference `users` and each other only, never a class, a
 * student, a child or a game, and no column holds a name or anything a
 * person typed: a player is shown under `users.name`, read when a page is
 * built.
 *
 * A room goes with its host's account (cascade); a player's row stays in
 * someone else's room without an account (`user_id` null, «Oyunçu») when
 * the account is deleted or the player takes their name out. The code is
 * unique by its column: `RoomCode` retries a collision, and closing a room
 * clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('code', 6)->nullable()->unique();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('last_activity_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
            $table->index(['closed_at', 'last_activity_at']);
            $table->index('created_at');
        });

        Schema::create('room_players', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_players');
        Schema::dropIfExists('rooms');
    }
};
