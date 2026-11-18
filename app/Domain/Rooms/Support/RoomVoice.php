<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomVoiceStanding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;

/**
 * Who stands where in a room's live voice (docs/architecture.md §12 "Live
 * voice", ADR 031 decisions 8, 12 and 18), from the rows the host wrote
 * and nothing else: who is in the voice, whose microphone is open and who
 * speaks are the voice server's and never read here.
 */
final class RoomVoice
{
    /** Whether the row is the host's own: always `open`, never held or muted. */
    public static function isHost(Room $room, RoomPlayer $row): bool
    {
        return $row->user_id !== null && $row->user_id === $room->user_id;
    }

    /**
     * The row's standing: the host's is `open`; a row the host has not let
     * in waits; one let in is `muted` while the host's mute stands, else
     * `open`.
     */
    public static function standingOf(Room $room, RoomPlayer $row): RoomVoiceStanding
    {
        return match (true) {
            self::isHost($room, $row) => RoomVoiceStanding::Open,
            $row->voice_admitted_at === null => RoomVoiceStanding::Waiting,
            $row->voice_muted_at !== null => RoomVoiceStanding::Muted,
            default => RoomVoiceStanding::Open,
        };
    }

    /**
     * What the state says of the row (§12 "The state's voice"): its
     * standing while the room's voice is on, for the host's row and the
     * present players' only; null otherwise.
     */
    public static function stateOf(Room $room, RoomPlayer $row): ?RoomVoiceStanding
    {
        if (! $room->voiceIsOn() || (! self::isHost($room, $row) && ! $row->isPresent())) {
            return null;
        }

        return self::standingOf($room, $row);
    }

    /**
     * Whether the row may hold a ticket: the host's, whether the host plays
     * or not, or a present player's who is let in (decision 18). The room's
     * own conditions — open, its voice on — are the caller's.
     */
    public static function mayListen(Room $room, RoomPlayer $row): bool
    {
        if ($row->removed_at !== null || $row->user_id === null) {
            return false;
        }

        return self::isHost($room, $row) || ($row->isPresent() && $row->voice_admitted_at !== null);
    }
}
