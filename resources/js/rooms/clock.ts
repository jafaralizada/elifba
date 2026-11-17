/**
 * The server's clock and when a room page asks for the state again
 * (docs/architecture.md §12 "The race" → "The state", "The channel",
 * ADR 021). The server alone keeps the clock: a round's phase is what the
 * state says, and the page never decides one or sends its own time. It
 * only needs to know when to ask, so it estimates the server's clock from
 * the `server_ms` of each state it fetches and asks again at the moment
 * the current phase ends — `opens_ms`, `answer_from_ms`, the bell,
 * `next_ms`. A page that asked a little early is told the same phase and
 * asks again shortly, a little later each time, so a clock that is off
 * never makes a hot loop (`room-state` allows 120 requests a minute). A
 * page that does not hear the channel also asks every 3 s in the lobby
 * and once it has answered, where only a signal would tell it of a change.
 *
 * The local clock and the timers come from outside, so Vitest fakes them;
 * nothing here judges, scores or derives a phase.
 */
import type { RoomState } from './contract';
import { POLL_MS } from './live';

/** A local clock in milliseconds; monotonic in the browser. */
export type LocalClock = () => number;

export type Delays = {
    after: (callback: () => void, ms: number) => unknown;
    cancel: (handle: unknown) => void;
};

/**
 * The server's clock as the page estimates it. Each state's `server_ms`
 * was read between the request's send and receive times, so it bounds the
 * difference between the two clocks; the bounds of every state are
 * intersected, and the estimate is the middle — for one state, the
 * midpoint of its own send and receive times. A state whose bounds miss
 * the others' (the device slept, its clock jumped) starts the estimate
 * again. A state in the page's props has no send time, only the moment it
 * was there by: until a fetched one comes, the server is taken to have
 * read its clock at that moment.
 */
export type ServerClock = {
    observe: (
        serverMs: number,
        sentAt: number | null,
        receivedAt: number,
    ) => void;
    /** The server's clock now, or null before the first state. */
    now: () => number | null;
};

const localNow: LocalClock = () => performance.now();

export function serverClock(local: LocalClock = localNow): ServerClock {
    // The server's clock minus the local one lies in [low, high].
    let low = -Infinity;
    let high = Infinity;

    return {
        observe: (serverMs, sentAt, receivedAt) => {
            const from = serverMs - receivedAt;
            const to = sentAt === null ? Infinity : serverMs - sentAt;

            if (from > high || to < low) {
                low = from;
                high = to;
            } else {
                low = Math.max(low, from);
                high = Math.min(high, to);
            }
        },
        now: () => {
            if (low === -Infinity) {
                return null;
            }

            return local() + (high === Infinity ? low : (low + high) / 2);
        },
    };
}

/**
 * The moment the shown phase ends by the timetable: the countdown at
 * `opens_ms`, the study at `answer_from_ms`, the answer at the bell (or
 * earlier, which only a state tells), the reveal at `next_ms`. Null in the
 * lobby and once the game is over, where nothing waits for a moment.
 */
export function phaseEnds(state: RoomState): number | null {
    const round = state.round;

    if (round === null) {
        return null;
    }

    switch (round.phase) {
        case 'countdown':
            return round.opens_ms;
        case 'study':
            return round.answer_from_ms;
        case 'answer':
            return round.bell_ms;
        case 'reveal':
            return round.next_ms;
    }
}

/**
 * Whether a page that does not hear the channel asks every 3 s: in the
 * lobby and after a game, and in a round once it has answered (or cannot:
 * a host who does not play), where it waits for the others — an early
 * close is told only by a signal or a state.
 */
export function pollsWithoutChannel(state: RoomState): boolean {
    return (
        state.round === null ||
        state.round.answered ||
        state.me.player_id === null
    );
}

/** A moment is asked about this long after it, so the server is past it too. */
export const LATE_MS = 60;

/**
 * How long a page waits before asking again when it was told the same
 * phase after its end, or when its request failed — a little longer each
 * time in a row.
 */
export const AGAIN_MS = [250, 500, 1000, 2000, POLL_MS] as const;

/** The wait before the n-th time in a row (from 1). */
function again(times: number): number {
    return AGAIN_MS[Math.min(Math.max(times - 1, 0), AGAIN_MS.length - 1)];
}

const realDelays: Delays = {
    after: (callback, ms) => window.setTimeout(callback, ms),
    cancel: (handle) => window.clearTimeout(handle as number),
};

export type RoomFollow = {
    /** The page now shows this state: plan the next request from it. */
    show: (state: RoomState) => void;
    /** Whether the room's channel is heard (`watchRoom`'s `onListening`). */
    listening: (heard: boolean) => void;
    /** A request — whoever made it — brought no state: no answer, a server error, the limiter. */
    failed: () => void;
    stop: () => void;
};

/**
 * Plans one request at a time from the state the page shows: at the end
 * of its phase, sooner every 3 s where `pollsWithoutChannel()` says so and
 * the channel is not heard. A request that failed — one this plan made,
 * or one a signal, a subscription or an answer started — is made again
 * shortly, a little later each time, until a state is shown: a page in the
 * lobby that hears the channel would otherwise wait for the next signal,
 * and miss the start of a game.
 */
export function followRoom(options: {
    clock: ServerClock;
    refresh: () => void;
    delays?: Delays;
}): RoomFollow {
    const delays = options.delays ?? realDelays;
    let shown: RoomState | null = null;
    let heard = false;
    let pending: unknown = null;
    let stopped = false;
    // The phase last shown, and how many times in a row it was shown after its end.
    let phase = '';
    let late = 0;
    // Requests that failed since a state was last shown.
    let misses = 0;

    const cancel = () => {
        if (pending !== null) {
            delays.cancel(pending);
            pending = null;
        }
    };

    const ask = () => {
        pending = null;
        options.refresh();
    };

    const plan = () => {
        cancel();

        if (stopped || shown === null) {
            return;
        }

        const ends = phaseEnds(shown);
        const now = options.clock.now();
        let wait: number | null = null;

        if (ends !== null) {
            wait =
                now !== null && ends - now > 0
                    ? ends - now + LATE_MS
                    : again(late);
        }

        if (!heard && pollsWithoutChannel(shown)) {
            wait = Math.min(wait ?? POLL_MS, POLL_MS);
        }

        if (misses > 0) {
            wait = Math.min(wait ?? Infinity, again(misses));
        }

        if (wait !== null) {
            pending = delays.after(ask, wait);
        }
    };

    return {
        show: (state) => {
            const round = state.round;
            const key = `${state.game?.id ?? ''}.${round?.id ?? ''}.${round?.phase ?? ''}`;
            const ends = phaseEnds(state);
            const now = options.clock.now();
            const over = ends !== null && now !== null && ends - now <= 0;

            late = over ? (key === phase ? late + 1 : 1) : 0;
            phase = key;
            shown = state;
            misses = 0;
            plan();
        },
        listening: (next) => {
            heard = next;
            plan();
        },
        failed: () => {
            misses++;
            plan();
        },
        stop: () => {
            stopped = true;
            cancel();
        },
    };
}
