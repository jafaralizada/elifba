<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Enums\RoomVoiceStanding;
use App\Domain\Rooms\Exceptions\RoomVoiceNotCut;
use App\Domain\Rooms\Exceptions\RoomVoiceUnreachable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomVoice;
use App\Domain\Rooms\Support\RoomVoiceServer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Makes the voice server match the facts (docs/architecture.md §12 "Live
 * voice" — "Enforcement at the voice server", ADR 031 decision 23):
 * `rooms:voice-sweep`, every 15 seconds while a voice server is named
 * (`schedule()`), the switch on or off — the command reads the switch
 * (`RoomsArea::voice()`) and passes it in. It lists the voice rooms and
 * compares each with its room:
 *
 * - a voice room whose room is gone, not open (closed or idle), has its
 *   voice off or another epoch — or any voice room at all while the
 *   instance's voice is off, or one the app did not name — is deleted;
 * - in a voice room that may stay, a participant who may not be there (not
 *   the host's row, not a present player let in) or a muted player who
 *   may publish means a ticket from before a fact was replayed, or a cut
 *   that did not arrive: the epoch is raised under the room row's lock —
 *   no revision, the state carries no epoch — and the voice room is
 *   deleted, so every old ticket dies and each page comes back with a new
 *   one.
 *
 * One exception keeps an honest leave or mute from cutting everyone (C3,
 * §12 "As built (C3)"): within `ticket_seconds` of the player's own leave
 * or mute the action's cut may still be on its way, or a ticket signed a
 * moment before may have joined after it; then the sweep makes that one
 * call itself — `RemoveParticipant`, or `UpdateParticipant` without the
 * microphone — and raises nothing. And a player the host let speak who is
 * listed without the microphone gets it back: that direction grants
 * nothing the facts do not.
 *
 * It reads and writes no round, no clock and nothing of `RoomWaits`. A
 * voice server that does not answer is reported as `RoomVoiceNotCut` and
 * tried again at the next sweep.
 */
final readonly class SweepRoomVoices
{
    /** A voice room's name the app gives: `{rooms.id}.{voice_epoch}`. */
    private const string NAME = '/^([0-9a-hjkmnp-tv-z]{26})\.(\d{1,9})$/';

    public function __construct(private RoomVoiceServer $server) {}

    /**
     * Registers the sweep while a voice server is named: an instance
     * without one schedules nothing and never calls a voice host that is
     * not there (§10 "Live voice").
     */
    public static function schedule(Schedule $schedule): void
    {
        if (RoomVoiceServer::configured()) {
            $schedule->command('rooms:voice-sweep')->everyFifteenSeconds();
        }
    }

    /**
     * @param  bool  $voice  whether the instance's live voice is on
     * @return int how many voice rooms were closed
     */
    public function handle(bool $voice): int
    {
        try {
            $names = $this->server->rooms();
        } catch (RoomVoiceUnreachable $failure) {
            report(new RoomVoiceNotCut('*', $failure->call, $failure->cause));

            return 0;
        }

        $closed = 0;

        foreach ($names as $name) {
            try {
                $closed += $this->sweep($name, $voice) ? 1 : 0;
            } catch (RoomVoiceUnreachable $failure) {
                report(new RoomVoiceNotCut(self::roomIdOf($name), $failure->call, $failure->cause));
            }
        }

        return $closed;
    }

    /**
     * @return bool whether the voice room was closed
     *
     * @throws RoomVoiceUnreachable
     */
    private function sweep(string $name, bool $voice): bool
    {
        $match = [];
        $room = $voice && preg_match(self::NAME, $name, $match) === 1 ? Room::query()->find($match[1]) : null;
        $epoch = (int) ($match[2] ?? -1);

        if ($room === null || ! $room->isOpen() || ! $room->voiceIsOn() || $room->voice_epoch !== $epoch) {
            $this->server->deleteRoom($name);

            return true;
        }

        $patches = $this->patchesFor($room, $name);

        if ($patches !== null) {
            foreach ($patches as $patch) {
                $patch();
            }

            return false;
        }

        DB::transaction(static function () use ($room, $epoch): void {
            $locked = RoomLock::room($room);

            if ($locked !== null && $locked->voice_epoch === $epoch) {
                $locked->voice_epoch = $epoch + 1;
                $locked->save();
            }
        });

        $this->server->deleteRoom($name);

        return true;
    }

    /**
     * The calls that bring the voice room back to the facts one participant
     * at a time — none when it matches them — or null when only a new epoch
     * will: someone is in it who may not be, and not because of their own
     * leave or mute within `ticket_seconds`.
     *
     * @return list<Closure(): void>|null
     *
     * @throws RoomVoiceUnreachable
     */
    private function patchesFor(Room $room, string $name): ?array
    {
        // Who is in it first, the rows after: a ticket is signed only after
        // its admission committed, so every row read here knows it.
        $participants = $this->server->participants($name);
        $rows = $room->players()->whereNull('removed_at')->get()->keyBy('id');
        $recent = CarbonImmutable::now()->subSeconds(Config::integer('elifba.rooms.voice.ticket_seconds'));
        $patches = [];

        foreach ($participants as $participant) {
            $identity = $participant['identity'];
            $row = $rows->get($identity);

            if ($row === null) {
                return null;
            }

            $standing = RoomVoice::standingOf($room, $row);

            if (! RoomVoice::mayListen($room, $row)) {
                // A player who left a moment ago: the leave's own cut, again.
                if ($row->user_id === null || $row->left_at === null || $row->left_at->lte($recent)) {
                    return null;
                }

                $patches[] = fn () => $this->server->removeParticipant($name, $identity);
            } elseif ($participant['can_publish'] && $standing !== RoomVoiceStanding::Open) {
                // Muted a moment ago: the mute's own cut, again.
                if ($row->voice_muted_at === null || $row->voice_muted_at->lte($recent)) {
                    return null;
                }

                $patches[] = fn () => $this->server->updateParticipant($name, $identity, false);
            } elseif (! $participant['can_publish'] && $standing === RoomVoiceStanding::Open) {
                // Let speak, and the permission did not arrive: give it back.
                $patches[] = fn () => $this->server->updateParticipant($name, $identity, true);
            }
        }

        return $patches;
    }

    private static function roomIdOf(string $name): string
    {
        return preg_match(self::NAME, $name, $match) === 1 ? $match[1] : '?';
    }
}
