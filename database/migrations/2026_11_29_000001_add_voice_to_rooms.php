<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Live voice in a room (docs/architecture.md §1 `users`, `rooms`,
 * `room_players`, §12 "Live voice", ADR 031 decision 7): the five facts a
 * voice needs and nothing more — when the account accepted the voice's
 * sentence, when the host turned a room's voice on and the epoch in its
 * voice room's name, and whether the host let a player into the voice and
 * muted them. Never who joined, whose microphone was open, who spoke or
 * anything said.
 *
 * Additive and nullable (the epoch starts at 0): rooms are live in
 * production, and the columns exist whether or not the switch is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('voice_agreed_at')->nullable();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('voice_at')->nullable();
            $table->unsignedInteger('voice_epoch')->default(0);
        });

        Schema::table('room_players', function (Blueprint $table) {
            $table->timestamp('voice_admitted_at')->nullable();
            $table->timestamp('voice_muted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropColumn(['voice_admitted_at', 'voice_muted_at']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['voice_at', 'voice_epoch']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('voice_agreed_at');
        });
    }
};
