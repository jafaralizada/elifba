import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { state as stateRoute } from '@/routes/rooms';
import { followRoom, serverClock } from '@/rooms/clock';
import type { RoomFollow, ServerClock } from '@/rooms/clock';
import type { RoomState } from '@/rooms/contract';
import { fetchState } from '@/rooms/http';
import type { RoomWatch } from '@/rooms/live';
import { oneAtATime, onShown, watchRoom } from '@/rooms/live';
import { connectRoomEcho, roomChannel } from '@/rooms/socket';

/** Whether `next` may replace `shown`: a fetch that was overtaken never covers a newer state. */
function newer(next: RoomState, shown: RoomState | null): boolean {
    return (
        shown === null ||
        next.revision > shown.revision ||
        (next.revision === shown.revision && next.server_ms >= shown.server_ms)
    );
}

/**
 * A room's state as a page follows it (architecture §12 "The race", "The
 * big screen"): the room page and the screen start from the state of their
 * props and read it again when `room.changed` brings a higher revision, at
 * each moment of the timetable (`followRoom()`: the server's clock is
 * estimated from the states fetched), by polling where only a signal would
 * tell it of a change, and whenever it is shown again (`onShown()`). A
 * page sends no client event, and a fetched state older than the
 * one shown is dropped. A room that refused the viewer reloads the page,
 * which says why; one that closed calls `onClosed` (by default the same
 * reload: the room page becomes its results).
 */
export function useLiveRoom({
    room,
    initial,
    onClosed = () => router.reload(),
    answers = true,
    beatMs = null,
}: {
    room: { id: string; status: 'open' | 'closed' };
    initial: RoomState | null;
    onClosed?: () => void;
    /**
     * Whether the page answers for its viewer: false for the big screen
     * (`followRoom()`), which asks for the state as the screen
     * (`?for=screen`: every taunt shown now with its target, T2).
     */
    answers?: boolean;
    /**
     * The screen's beat (`beat_ms`): while it is shown it reads the state at
     * least this often, and it reads nothing while hidden, so the state's
     * `room.screen` tells every phone whether the room has its screen (ADR
     * 028 as amended 2026-11-19). Null on a phone.
     */
    beatMs?: number | null;
}): {
    state: RoomState | null;
    clock: ServerClock;
    refresh: () => void;
} {
    const [state, setState] = useState(initial);
    const [clock] = useState(() => serverClock());
    const live = room.status === 'open' && initial !== null;
    // One socket for the page's life, not one per reload of its props.
    const watchRef = useRef<RoomWatch | null>(null);
    const followRef = useRef<RoomFollow | null>(null);
    const refreshRef = useRef<() => void>(() => {});
    const firstRevision = useRef(initial?.revision ?? 0);
    // Two fetches may answer out of order: an older state never covers a newer one.
    const shown = useRef<RoomState | null>(initial);
    const closed = useRef(onClosed);

    useEffect(() => {
        closed.current = onClosed;
    }, [onClosed]);

    useEffect(() => {
        if (initial === null) {
            setState(null);

            return;
        }

        // The props carry no send time: read by the moment they are here.
        clock.observe(initial.server_ms, null, performance.now());

        if (newer(initial, shown.current) || shown.current === initial) {
            shown.current = initial;
            setState(initial);
            watchRef.current?.seen(initial.revision);
            followRef.current?.show(initial);
        }
    }, [initial, clock]);

    useEffect(() => {
        if (!live) {
            return;
        }

        let stopped = false;
        let echo: ReturnType<typeof connectRoomEcho> | null = null;

        try {
            echo = connectRoomEcho();
        } catch {
            echo = null;
        }

        // A hidden screen asks nothing: then the room has no screen, and phones carry its sound.
        const asleep = () => !answers && document.visibilityState === 'hidden';

        // A burst of answers is a burst of signals: one request at a time.
        const refresh = oneAtATime(() =>
            (stopped || asleep()
                ? Promise.resolve(null)
                : fetchState(
                      answers
                          ? stateRoute.url(room.id)
                          : stateRoute.url(room.id, {
                                query: { for: 'screen' },
                            }),
                  )
            ).then((result) => {
                if (stopped || result === null) {
                    return;
                }

                if (result.kind === 'failed') {
                    follow.failed();

                    return;
                }

                if (result.kind === 'refused') {
                    // Removed, gone or closed: the page says which.
                    router.reload();

                    return;
                }

                const next = result.state;
                clock.observe(next.server_ms, result.sentAt, result.receivedAt);

                if (!newer(next, shown.current)) {
                    return;
                }

                shown.current = next;
                watch.seen(next.revision);

                if (next.room.status !== room.status) {
                    closed.current();
                } else {
                    setState(next);
                    follow.show(next);
                }
            }),
        );

        const follow = followRoom({ clock, refresh, answers });
        const watch = watchRoom({
            revision: firstRevision.current,
            channel: echo === null ? null : roomChannel(echo, room.id),
            refresh,
            onListening: follow.listening,
        });

        // A phone woken from its lock, a tab brought back: read the room at once.
        const unshown = onShown(document, refresh);

        // The screen's beat: a shown screen reads at least this often.
        const beat =
            beatMs === null ? null : window.setInterval(refresh, beatMs);

        watchRef.current = watch;
        followRef.current = follow;
        refreshRef.current = refresh;

        if (shown.current !== null) {
            follow.show(shown.current);
        }

        // The props bound the server's clock from one side only: a fetch bounds it from both.
        refresh();

        return () => {
            stopped = true;
            watchRef.current = null;
            followRef.current = null;
            refreshRef.current = () => {};

            if (beat !== null) {
                window.clearInterval(beat);
            }

            unshown();
            follow.stop();
            watch.stop();
        };
    }, [live, room.id, room.status, clock, answers, beatMs]);

    return { state, clock, refresh: () => refreshRef.current() };
}
