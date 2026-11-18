import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import {
    carriesRoom,
    roomCues,
    sameMoment,
} from '@/components/rooms/sound-cues';
import type { RoomEar } from '@/components/rooms/sound-cues';
import type { ServerClock } from '@/rooms/clock';
import type { RoomState } from '@/rooms/contract';
import { roomSoundPlayer } from '@/rooms/sound';
import type {
    PhoneSound,
    RoomFinishVoice,
    RoomSoundState,
    RoomSoundUrls,
    RoomSpeaker,
} from '@/rooms/sound';

/** How often the page listens for a beat of the clock (a countdown's second, a last second). */
const LISTEN_MS = 200;

/**
 * What the screen says after the final's fanfare (ADR 030): a member's
 * first-name clip by ids, and the end phrases' URLs from its props.
 */
export type RoomCheer = {
    nameUrl: (playerId: string) => string;
    finish: RoomFinishVoice;
};

/** What a page's sound controls offer: its state, the toggle and the phone's own taps. */
export type RoomSound = RoomSoundState & {
    /** The toggle, a gesture: on the screen it first unlocks; then it mutes and unmutes. */
    toggle: () => void;
    /** A phone's own tap or undo, played where it is made. */
    tap: (sound: Extract<PhoneSound, 'tap' | 'undo'>) => void;
};

const NO_SOUND: RoomSound = {
    muted: false,
    locked: true,
    toggle: () => {},
    tap: () => {},
};

const noSubscription = () => () => {};

/** The gestures that let a page make sound (a touch counts when it ends). */
const GESTURES = ['pointerup', 'touchend', 'keydown', 'click'] as const;

/**
 * Whether the browser lets this document make sound before a new gesture:
 * it already had one (`userActivation`, kept across Inertia visits), or
 * says so itself (`getAutoplayPolicy`, where there is one).
 */
function allowedWithoutGesture(): boolean {
    try {
        const browser = navigator as Navigator & {
            userActivation?: { hasBeenActive: boolean };
            getAutoplayPolicy?: (type: 'audiocontext') => string;
        };

        return (
            browser.userActivation?.hasBeenActive === true ||
            browser.getAutoplayPolicy?.('audiocontext') === 'allowed'
        );
    } catch {
        return false;
    }
}

/**
 * A room page's sound (ADR 025 "Sound", ADR 028, docs/frontend.md rule 15
 * "Sound"): the player of its speaker's files (`sounds`, null for none),
 * fed with what the page hears — the state and the estimated server clock
 * every 200 ms — through `roomCues()`; a phone in a room without a screen
 * carries the room's cues too (`carriesRoom()`). The first gesture on the page
 * unlocks sound unless the device muted it (at once where the document
 * already had one, as after «Qoşul»). Every cue stops when its moment
 * ends — one scheduled is dropped, one sounding fades out at once — and
 * when the page is hidden; coming back wakes the sound where the browser
 * lets it and plays nothing that was missed.
 *
 * `cheer` is the screen's alone (ADR 030): the route of a member's
 * first-name clip, by ids, and the URLs of the end phrases, for what it
 * says after the final's fanfare. A page without it — a phone — says
 * nothing.
 */
export function useRoomSound({
    speaker,
    sounds,
    state,
    clock,
    cheer,
}: {
    speaker: RoomSpeaker;
    sounds: RoomSoundUrls | null;
    state: RoomState | null;
    clock: ServerClock;
    cheer?: RoomCheer;
}): RoomSound {
    const [player] = useState(() =>
        sounds === null ? null : roomSoundPlayer({ speaker, urls: sounds }),
    );
    const snapshot = useSyncExternalStore(
        player?.subscribe ?? noSubscription,
        player?.snapshot ?? (() => NO_SOUND),
        () => NO_SOUND,
    );
    const stateRef = useRef(state);
    const cheerRef = useRef(cheer);
    const heard = useRef<RoomEar | null>(null);
    const listenRef = useRef<() => void>(() => {});

    useEffect(() => {
        cheerRef.current = cheer;
    }, [cheer]);

    // A new state is heard at once, not at the next beat of the clock.
    useEffect(() => {
        stateRef.current = state;
        listenRef.current();
    }, [state]);

    useEffect(() => {
        if (player === null) {
            return;
        }

        const listen = () => {
            const ear = { state: stateRef.current, now: clock.now() };
            const previous = heard.current;

            // A phone in a room without a screen fetches the room's files too.
            if (speaker === 'phone' && carriesRoom(ear.state)) {
                player.carry();
            }

            if (previous !== null && !sameMoment(previous, ear, speaker)) {
                player.cancel();
            }

            for (const cue of roomCues(previous, ear, speaker)) {
                const voice = cheerRef.current;

                if (cue.sound !== 'cheer') {
                    player.play(cue.sound, cue);
                } else if (voice !== undefined) {
                    player.say(
                        cue.players.map(voice.nameUrl),
                        cue.many ? voice.finish.many : voice.finish.one,
                        cue.at,
                    );
                }
            }

            heard.current = ear;
        };
        const unlock = (event?: Event) => {
            // The sound toggle decides for itself what its press means.
            const target = event?.target;

            if (
                target instanceof Element &&
                target.closest('[data-room-sound]') !== null
            ) {
                return;
            }

            if (player.snapshot().locked) {
                player.unlock();
            }
        };
        const visible = () => {
            if (document.visibilityState === 'visible') {
                player.resume();
            } else {
                player.hide();
            }
        };

        listenRef.current = listen;

        if (document.visibilityState === 'hidden') {
            player.hide();
        }

        listen();

        // A page reached by a tap (Inertia keeps the document) may sound at once.
        if (allowedWithoutGesture()) {
            unlock();
        }

        const timer = window.setInterval(listen, LISTEN_MS);
        GESTURES.forEach((name) =>
            document.addEventListener(name, unlock, { capture: true }),
        );
        document.addEventListener('visibilitychange', visible);

        return () => {
            listenRef.current = () => {};
            window.clearInterval(timer);
            GESTURES.forEach((name) =>
                document.removeEventListener(name, unlock, { capture: true }),
            );
            document.removeEventListener('visibilitychange', visible);
            player.release();
        };
    }, [player, clock, speaker]);

    if (player === null) {
        return NO_SOUND;
    }

    return {
        ...snapshot,
        // The screen's first press turns the room's sound on; after that, and on a phone, it mutes.
        toggle: () => {
            const now = player.snapshot();

            if (speaker === 'screen' && now.locked && !now.muted) {
                player.unlock();
            } else {
                player.setMuted(!now.muted);
            }
        },
        tap: (sound) => player.play(sound),
    };
}
