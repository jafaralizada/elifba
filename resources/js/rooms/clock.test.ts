import { describe, expect, it, vi } from 'vite-plus/test';
import type { Delays, ServerClock } from './clock';
import {
    AGAIN_MS,
    LATE_MS,
    barStretch,
    followRoom,
    phaseEnds,
    pollsWithoutChannel,
    serverClock,
    timeLeft,
} from './clock';
import { RoomState } from './contract';
import { POLL_MS } from './live';

/*
 * When a room page asks for the state (docs/architecture.md §12 "The
 * state", "The channel"): the server's clock is estimated from a state's
 * `server_ms` against the request's send and receive times; the page asks
 * again when the shown phase ends, again shortly when it was early, and
 * every 3 s without a channel only in the lobby and once it has answered.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): RoomState {
    return RoomState.parse(
        fixtures[`../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

/** A local clock and timers that move only when the test moves them. */
function fakeTime(start = 0) {
    let now = start;
    let next = 1;
    const pending = new Map<number, { at: number; callback: () => void }>();
    const delays: Delays = {
        after: (callback, ms) => {
            expect(ms).toBeGreaterThan(0);
            pending.set(next, { at: now + ms, callback });

            return next++;
        },
        cancel: (handle) => {
            pending.delete(handle as number);
        },
    };

    return {
        delays,
        pending,
        local: () => now,
        /** Moves the clock, running each timer whose moment comes, in order. */
        advance: (ms: number) => {
            const end = now + ms;

            for (;;) {
                const due = [...pending.entries()]
                    .filter(([, timer]) => timer.at <= end)
                    .sort(([, a], [, b]) => a.at - b.at)[0];

                if (due === undefined) {
                    break;
                }

                pending.delete(due[0]);
                now = due[1].at;
                due[1].callback();
            }

            now = end;
        },
        /** When the next timer fires, from now. */
        nextIn: () =>
            Math.min(...[...pending.values()].map((timer) => timer.at)) - now,
    };
}

/** The server's clock is 100 s ahead of the local one; both start together. */
const OFFSET = 100_000;

function synced(time: ReturnType<typeof fakeTime>): ServerClock {
    const clock = serverClock(time.local);
    clock.observe(time.local() + OFFSET, time.local(), time.local());

    return clock;
}

/** One round's moments, on the server's clock. */
const T = {
    opens: 103_000,
    answerFrom: 106_000,
    bell: 116_000,
    next: 121_000,
};

/** A fixture's state in its phase, moved onto the moments above. */
function at(name: string, patch: Record<string, unknown> = {}): RoomState {
    const fixture = state(name);
    const round =
        fixture.round === null
            ? null
            : {
                  ...fixture.round,
                  opens_ms: T.opens,
                  answer_from_ms: T.answerFrom,
                  bell_ms: T.bell,
                  ...(fixture.round.phase === 'reveal'
                      ? { closes_ms: T.bell, next_ms: T.next }
                      : {}),
                  ...patch,
              };

    return RoomState.parse({ ...fixture, round });
}

function follow(time: ReturnType<typeof fakeTime>, clock = synced(time)) {
    const refresh = vi.fn();
    const follow = followRoom({ clock, refresh, delays: time.delays });

    return { follow, refresh, clock };
}

describe('serverClock', () => {
    it("estimates the server's clock from a state", () => {
        const time = fakeTime(1000);
        const clock = serverClock(time.local);
        expect(clock.now()).toBeNull();

        // Sent at 1000, received at 1200: the server read 50 000 at 1100.
        time.advance(200);
        clock.observe(50_000, 1000, 1200);
        expect(clock.now()).toBe(50_100);
        time.advance(300);
        expect(clock.now()).toBe(50_400);

        // A quicker request narrows it: read between 2000 and 2020.
        time.advance(520);
        clock.observe(51_005, 2000, 2020);
        // The difference lies in [48 800, 49 000] ∩ [48 985, 49 005].
        expect(clock.now()).toBe(2020 + 48_992.5);
    });

    it('takes a state of the props as read when it was there, until a fetched one comes', () => {
        const time = fakeTime(5000);
        const clock = serverClock(time.local);

        clock.observe(80_000, null, 5000);
        expect(clock.now()).toBe(80_000);

        // The props were older than they looked: a fetched state moves the estimate on.
        time.advance(300);
        clock.observe(80_400, 5100, 5300);
        expect(clock.now()).toBe(5300 + 75_200);
    });

    it('starts again when a state misses the bounds of the others', () => {
        const time = fakeTime(0);
        const clock = serverClock(time.local);
        clock.observe(10_000, 0, 100);

        // The device slept and its clock stood still: the server is 60 s further on.
        time.advance(200);
        clock.observe(70_300, 100, 200);
        expect(clock.now()).toBe(200 + 70_150);
    });
});

describe('phaseEnds', () => {
    it('ends each phase at its moment of the timetable', () => {
        expect(phaseEnds(at('countdown'))).toBe(T.opens);
        expect(phaseEnds(at('study'))).toBe(T.answerFrom);
        expect(phaseEnds(at('answer'))).toBe(T.bell);
        expect(phaseEnds(at('reveal'))).toBe(T.next);
        expect(phaseEnds(state('lobby'))).toBeNull();
        expect(phaseEnds(state('over'))).toBeNull();
    });
});

describe('followRoom', () => {
    it('asks again at each moment of the timetable', () => {
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);
        room.listening(true);

        // The countdown ends when round 0 opens, a little after the moment.
        room.show(at('countdown'));
        expect(time.nextIn()).toBe(3000 + LATE_MS);
        time.advance(3000 + LATE_MS - 1);
        expect(refresh).not.toHaveBeenCalled();
        time.advance(1);
        expect(refresh).toHaveBeenCalledTimes(1);

        // The study ends at the question, the question at the bell, the reveal at the next round.
        room.show(at('study'));
        expect(time.nextIn()).toBe(
            T.answerFrom - (T.opens + LATE_MS) + LATE_MS,
        );
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(2);

        room.show(at('answer', { answered: false, answered_count: 0 }));
        expect(time.nextIn()).toBe(T.bell - T.answerFrom);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(3);

        room.show(at('reveal'));
        expect(time.nextIn()).toBe(T.next - T.bell);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(4);

        // Over: nothing waits for a moment while the channel is heard.
        room.show(state('over'));
        expect(time.pending.size).toBe(0);
    });

    it('asks again shortly, a little later each time, when it was early', () => {
        const time = fakeTime();
        // The estimate is a second ahead of the server, which keeps telling the study.
        const clock = serverClock(time.local);
        clock.observe(OFFSET + 1000, 0, 0);
        const { follow: room, refresh } = follow(time, clock);
        room.listening(true);

        room.show(at('study'));
        expect(time.nextIn()).toBe(T.answerFrom - (OFFSET + 1000) + LATE_MS);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(1);

        const waits: number[] = [];

        for (let i = 0; i < 7; i++) {
            room.show(at('study'));
            waits.push(time.nextIn());
            time.advance(time.nextIn());
        }

        expect(waits).toEqual([...AGAIN_MS, POLL_MS, POLL_MS]);
        expect(AGAIN_MS).toEqual([250, 500, 1000, 2000, POLL_MS]);
        expect(refresh).toHaveBeenCalledTimes(8);

        // Another phase after its end starts the count again.
        room.show(at('answer', { bell_ms: T.answerFrom + 5000 }));
        expect(time.nextIn()).toBe(250);
    });

    it('polls when there is no socket', () => {
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);

        // The lobby: every 3 s while the channel is not heard, never while it is.
        room.show(state('lobby'));
        expect(time.nextIn()).toBe(POLL_MS);
        time.advance(POLL_MS);
        room.show(state('lobby'));
        time.advance(POLL_MS);
        expect(refresh).toHaveBeenCalledTimes(2);

        room.listening(true);
        expect(time.pending.size).toBe(0);
        room.listening(false);
        expect(time.nextIn()).toBe(POLL_MS);

        // A question not yet answered: only at the bell.
        const unanswered = at('answer', { answered: false, answered_count: 0 });
        expect(pollsWithoutChannel(unanswered)).toBe(false);
        room.show(unanswered);
        expect(time.nextIn()).toBe(T.bell - (OFFSET + 2 * POLL_MS) + LATE_MS);

        // Once answered, every 3 s: an early close is told only by a state.
        room.show(at('answer'));
        expect(time.nextIn()).toBe(POLL_MS);

        // The countdown, the study and the reveal: only at their moments.
        expect(pollsWithoutChannel(at('countdown'))).toBe(false);
        expect(pollsWithoutChannel(at('study'))).toBe(false);
        expect(pollsWithoutChannel(at('reveal', { answered: false }))).toBe(
            false,
        );

        // A host who does not play waits as one who answered; after a game, as in the lobby.
        expect(
            pollsWithoutChannel(
                RoomState.parse({
                    ...unanswered,
                    me: { player_id: null, host: true },
                }),
            ),
        ).toBe(true);
        expect(pollsWithoutChannel(state('over'))).toBe(true);
    });

    it('asks again when a request fails, whoever made it, a little later each time', () => {
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);

        // The lobby with the channel heard: nothing is planned until a signal.
        room.listening(true);
        room.show(state('lobby'));
        expect(time.pending.size).toBe(0);

        // The fetch a signal started fails (the server, the limiter, the network):
        // the page asks again rather than wait for another signal.
        const waits: number[] = [];

        for (let i = 0; i < 6; i++) {
            room.failed();
            waits.push(time.nextIn());
            time.advance(time.nextIn());
        }

        expect(waits).toEqual([...AGAIN_MS, POLL_MS]);
        expect(refresh).toHaveBeenCalledTimes(6);

        // A state shown ends it and starts the count again.
        room.show(at('countdown', { opens_ms: OFFSET + time.local() + 3000 }));
        expect(time.nextIn()).toBe(3000 + LATE_MS);
        room.failed();
        expect(time.nextIn()).toBe(AGAIN_MS[0]);

        // A request that only took long is not asked again.
        room.show(state('lobby'));
        time.advance(60_000);
        expect(refresh).toHaveBeenCalledTimes(6);
    });

    it('stops', () => {
        const time = fakeTime();
        const { follow: room } = follow(time);
        room.show(at('study'));
        expect(time.pending.size).toBe(1);

        room.stop();
        expect(time.pending.size).toBe(0);
        room.show(at('study'));
        room.failed();
        room.listening(false);
        expect(time.pending.size).toBe(0);
    });
});

describe('the time bar', () => {
    it('draws the same bar on a slow clock', () => {
        // Two phones fetch the same state at the same real moment; the second one's clock is 37 s behind.
        const fast = fakeTime(1000);
        const slow = fakeTime(1000 - 37_000);
        const clocks = [fast, slow].map((time) => {
            const clock = serverClock(time.local);
            // Sent at the real moment 1000, answered 80 ms later; the server read its clock in between.
            const sent = time.local();
            time.advance(80);
            clock.observe(T.answerFrom + 2000, sent, time.local());

            return { time, clock };
        });
        const round = at('answer').round;
        expect(round).not.toBeNull();

        const stretch = barStretch(round!);
        expect(stretch).toEqual({ from: T.answerFrom, to: T.bell });

        const bars = () =>
            clocks.map(({ clock }) =>
                timeLeft(stretch!.from, stretch!.to, clock.now()),
            );

        // 2 s of the 10 s answer have gone (and half the request's 80 ms): the same on both.
        const [first, second] = bars();
        expect(first).toBeCloseTo(1 - 2040 / 10_000, 9);
        expect(second).toBe(first);

        // Later, still the same; the device's own clock would have drawn the slow phone's bar 37 s off.
        for (const { time } of clocks) {
            time.advance(5000);
        }
        const [later, laterSlow] = bars();
        expect(later).toBeCloseTo(1 - 7040 / 10_000, 9);
        expect(laterSlow).toBe(later);
        expect(
            timeLeft(stretch!.from, stretch!.to, slow.local() + OFFSET),
        ).not.toBeCloseTo(later, 2);
    });

    it('is full before the first state and empty after its end, never beyond', () => {
        expect(timeLeft(1000, 2000, null)).toBe(1);
        expect(timeLeft(1000, 2000, 500)).toBe(1);
        expect(timeLeft(1000, 2000, 1500)).toBe(0.5);
        expect(timeLeft(1000, 2000, 2500)).toBe(0);
        expect(timeLeft(2000, 2000, 2000)).toBe(0);
    });

    it('measures the study, the answer and the wait to the next round', () => {
        expect(barStretch(at('countdown').round!)).toBeNull();
        expect(barStretch(at('study').round!)).toEqual({
            from: T.opens,
            to: T.answerFrom,
        });
        expect(barStretch(at('reveal').round!)).toEqual({
            from: T.bell,
            to: T.next,
        });
    });
});
