<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Exceptions\RoomVoiceNotCut;
use App\Domain\Rooms\Exceptions\RoomVoiceUnreachable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The call an action owes the voice server for a fact it wrote
 * (docs/architecture.md §12 "Live voice" — "Enforcement at the voice
 * server", ADR 031 decisions 22 and 24). The action calls it on the room
 * row it holds locked, after `RoomSignal::raise()`; the call goes out once
 * the transaction commits — after `room.changed`, never inside the
 * transaction or under the room's lock, never when it rolls back — through
 * `RoomVoiceServer`, in the same request, with no queue. A call that fails
 * or times out never fails the write: it is reported as `RoomVoiceNotCut`
 * with the room id and the cause only, and `SweepRoomVoices` finishes the
 * job. Nothing is called while no voice server is named.
 */
final class RoomVoiceCut
{
    /** The voice turned on: the voice room is made. */
    public static function open(Room $locked): void
    {
        $name = $locked->voiceRoom();

        self::after($locked, 'CreateRoom', static fn (RoomVoiceServer $server) => $server->createRoom($name));
    }

    /**
     * A removal, the voice turned off, a close: the voice room of the epoch
     * before the raise is deleted, and everyone in it is disconnected.
     */
    public static function close(Room $locked, int $epochBefore): void
    {
        $name = $locked->voiceRoom($epochBefore);

        self::after($locked, 'DeleteRoom', static fn (RoomVoiceServer $server) => $server->deleteRoom($name));
    }

    /** A player muted, or let speak again: the whole permission set, with or without the microphone. */
    public static function permit(Room $locked, RoomPlayer $row, bool $maySpeak): void
    {
        $name = $locked->voiceRoom();
        $identity = $row->id;

        self::after($locked, 'UpdateParticipant', static fn (RoomVoiceServer $server) => $server->updateParticipant($name, $identity, $maySpeak));
    }

    /** A player left the room: taken out of the voice room. */
    public static function remove(Room $locked, RoomPlayer $row): void
    {
        $name = $locked->voiceRoom();
        $identity = $row->id;

        self::after($locked, 'RemoveParticipant', static fn (RoomVoiceServer $server) => $server->removeParticipant($name, $identity));
    }

    /**
     * @param  Closure(RoomVoiceServer): void  $call
     */
    private static function after(Room $locked, string $what, Closure $call): void
    {
        if (! RoomVoiceServer::configured()) {
            return;
        }

        $roomId = $locked->id;

        DB::afterCommit(static function () use ($roomId, $what, $call): void {
            try {
                $call(app(RoomVoiceServer::class));
            } catch (RoomVoiceUnreachable $failure) {
                report(new RoomVoiceNotCut($roomId, $what, $failure->cause));
            } catch (Throwable $failure) {
                report(new RoomVoiceNotCut($roomId, $what, $failure::class));
            }
        });
    }
}
