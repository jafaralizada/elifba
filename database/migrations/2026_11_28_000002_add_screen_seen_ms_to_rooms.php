<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When the room's big screen last read the state while it was shown
 * (docs/architecture.md §1 `rooms`, §12 "A room without a screen", ADR 028
 * as amended 2026-11-19): Unix milliseconds of the server's clock, like
 * every moment the race compares (`RoomClock`). The state's `room.screen`
 * is derived from it (`RoomScreen::present()`): a room whose screen has
 * not read within `RoomScreen::goneMs()` has none, and then every phone
 * plays the room's cues too. Written only by `RecordScreenRead`.
 *
 * Additive and nullable: rooms are live in production. A room open at the
 * migration has no screen until its screen reads again — within a beat
 * (`elifba.rooms.screen.beat_ms`) of the new page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->bigInteger('screen_seen_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('screen_seen_ms');
        });
    }
};
