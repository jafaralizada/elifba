/**
 * A room page's live link (docs/architecture.md §12 "The race", ADR 021
 * decisions 8–9). The room's channel, `private-room.{id}`, carries one
 * signal from the server, `room.changed` `{v: 1, revision}`; a page that
 * sees a revision above its own fetches the state, which is everything. No
 * client event means anything in a room: this module sends none and
 * listens for none — the channel below has no whisper.
 *
 * A signal sent before the channel is subscribed is lost, so the page asks
 * for the state every `POLL_MS` until the subscription succeeds, once more
 * when it does (the first and every one after a reconnect), and every
 * `POLL_MS` again whenever the socket drops or the subscription fails. A
 * page that plays a game asks by its own rules instead (`followRoom()` in
 * `clock.ts`: at the timetable's moments, and every `POLL_MS` only in the
 * lobby and while it waits for the others): it passes `onListening` and is
 * told when the channel is heard and when it is not, and this module does
 * not poll.
 *
 * The channel comes from outside (`roomChannel()` in `socket.ts` adapts
 * Echo; tests use a fake), so this module never loads Echo.
 */
import { RoomChangedEvent } from './contract';

export type SocketState = 'connecting' | 'connected' | 'disconnected';

/** A room's channel as this module sees it: the server's signal and the socket, nothing to send. */
export type RoomChannel = {
    onChanged: (listener: (payload: unknown) => void) => void;
    /** Every successful subscription, the first and each one after a reconnect. */
    onSubscribed: (listener: () => void) => void;
    /** The subscription was refused or failed. */
    onError: (listener: () => void) => void;
    onSocket: (listener: (state: SocketState) => void) => void;
    close: () => void;
};

export type Timers = {
    every: (callback: () => void, ms: number) => unknown;
    cancel: (handle: unknown) => void;
};

/**
 * How often a page without a subscribed channel asks for the state: well
 * inside the 3 s countdown and the 3 s reveal, so a page without a socket
 * shows them both; two pages of one account (the host's phone and the
 * screen) stay under `room-state`'s 120 requests a minute.
 */
export const POLL_MS = 1500;

/**
 * How soon a socket that hears nothing is found dead (`connectRoomEcho()`
 * in `socket.ts` gives these to pusher-js, which pings
 * after `activityTimeout` of silence — the lower of this and Reverb's 30 s
 * — and closes when no pong comes within `pongTimeout`): about 15 s
 * rather than a minute. Until then the page takes the channel for heard;
 * the rooms-flow check (2026-11-18) saw a phone whose socket heard
 * nothing stay in the lobby while the game it missed ran on. Closed, it
 * polls (`watchRoom()`) and reconnects, and reads the state once
 * subscribed again.
 */
export const SOCKET_TIMEOUTS = {
    activityTimeout: 10_000,
    pongTimeout: 5_000,
} as const;

const realTimers: Timers = {
    every: (callback, ms) => window.setInterval(callback, ms),
    cancel: (handle) => window.clearInterval(handle as number),
};

export type RoomWatch = {
    /** The page now shows this revision (from a fetched state). */
    seen: (revision: number) => void;
    stop: () => void;
};

/**
 * Calls `refresh` when the channel signals a revision above the page's,
 * once on every subscription, and every `POLL_MS` while the channel is not
 * subscribed (or there is no channel at all) — unless `onListening` is
 * given: then it is told, on every change, whether the channel is heard,
 * and the page polls by its own rules. A malformed signal is dropped.
 */
export function watchRoom(options: {
    revision: number;
    channel: RoomChannel | null;
    refresh: () => void;
    timers?: Timers;
    onListening?: (listening: boolean) => void;
}): RoomWatch {
    const timers = options.timers ?? realTimers;
    const onListening = options.onListening;
    let revision = options.revision;
    let poll: unknown = null;
    let listening: boolean | null = null;
    let stopped = false;

    const hear = (heard: boolean) => {
        if (stopped || heard === listening) {
            return;
        }

        listening = heard;

        if (onListening !== undefined) {
            onListening(heard);
        } else if (!heard && poll === null) {
            poll = timers.every(options.refresh, POLL_MS);
        } else if (heard && poll !== null) {
            timers.cancel(poll);
            poll = null;
        }
    };
    const startPolling = () => hear(false);
    const stopPolling = () => {
        if (poll !== null) {
            timers.cancel(poll);
            poll = null;
        }
    };

    // Until the channel is subscribed, a signal may be missed.
    startPolling();

    if (options.channel !== null) {
        options.channel.onChanged((payload) => {
            const event = RoomChangedEvent.safeParse(payload);

            if (!stopped && event.success && event.data.revision > revision) {
                revision = event.data.revision;
                options.refresh();
            }
        });
        options.channel.onSubscribed(() => {
            if (stopped) {
                return;
            }

            hear(true);
            // Whatever changed while the channel was not listening.
            options.refresh();
        });
        options.channel.onError(startPolling);
        options.channel.onSocket((state) => {
            if (state !== 'connected') {
                startPolling();
            }
        });
    }

    return {
        seen: (next) => {
            revision = Math.max(revision, next);
        },
        stop: () => {
            stopped = true;
            stopPolling();
            options.channel?.close();
        },
    };
}

/** The page as `onShown()` sees it: `document` in the browser, a fake in tests. */
export type ShownPage = {
    readonly visibilityState: DocumentVisibilityState;
    addEventListener: (type: 'visibilitychange', listener: () => void) => void;
    removeEventListener: (
        type: 'visibilitychange',
        listener: () => void,
    ) => void;
};

/**
 * Calls `refresh` whenever the page is shown again — a phone woken from
 * its lock, a tab brought back (ADR 021 decision 9). Its timers may have
 * slept through a moment, and its socket may have died with nothing yet
 * the wiser; one read says where the room is. Never while it is hidden.
 * Returns the function that stops listening.
 */
export function onShown(page: ShownPage, refresh: () => void): () => void {
    const listener = () => {
        if (page.visibilityState === 'visible') {
            refresh();
        }
    };

    page.addEventListener('visibilitychange', listener);

    return () => page.removeEventListener('visibilitychange', listener);
}

/**
 * Runs `request` one at a time: a call made while one is in flight is
 * not sent at once but folded, with any others, into one more request
 * when it ends. Every accepted answer raises the room's revision, so a
 * burst of answers is a burst of signals; each page then asks for the
 * state at most twice for it, never once per answer (`room-state` allows
 * 120 requests a minute).
 */
export function oneAtATime(request: () => Promise<unknown>): () => void {
    let busy = false;
    let again = false;

    const run = () => {
        if (busy) {
            again = true;

            return;
        }

        busy = true;
        again = false;
        void request()
            .catch(() => undefined)
            .finally(() => {
                busy = false;

                if (again) {
                    run();
                }
            });
    };

    return run;
}
