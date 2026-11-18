<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * When a player's own room page last read the state of a running game
 * (docs/architecture.md §1 `room_players`, §12 "The race", ADR 021
 * decision 7): Unix milliseconds of the server's clock, like every moment
 * the race compares (`RoomClock`). A round waits for a present player who
 * has answered it or whose page has read the state since the round before
 * it closed (`RoomWaits`), so a phone that is simply gone holds no round
 * after the one it vanished in. Written only by `RecordRoomRead`, at most
 * once a round; never by the big screen.
 *
 * Additive and nullable: rooms are live in production. A present player
 * of an open room counts as read at the moment of the migration, so the
 * round running then waits for everyone as rounds did before; after it,
 * each live page's own reads tell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->bigInteger('seen_ms')->nullable();
        });

        DB::table('room_players')
            ->whereNotNull('user_id')
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->whereIn('room_id', DB::table('rooms')->whereNull('closed_at')->select('id'))
            ->update(['seen_ms' => (int) CarbonImmutable::now()->getTimestampMs()]);
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropColumn('seen_ms');
        });
    }
};
