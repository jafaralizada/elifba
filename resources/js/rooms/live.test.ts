import { describe, expect, it, vi } from 'vite-plus/test';
import type { RoomChannel, SocketState, Timers } from './live';
import { POLL_MS, watchRoom } from './live';

/*
 * A room page's live link (docs/architecture.md §12 "The race"): it fetches
 * on a higher revision and on every subscription, polls until the channel
 * is subscribed and whenever it is lost, and has nothing to send.
 */

function fakeChannel() {
    let changed: (payload: unknown) => void = () => {};
    let subscribed: () => void = () => {};
    let failed: () => void = () => {};
    let socket: (state: SocketState) => void = () => {};
    const close = vi.fn<() => void>();
    const channel: RoomChannel = {
        onChanged: (listener) => {
            changed = listener;
        },
        onSubscribed: (listener) => {
            subscribed = listener;
        },
        onError: (listener) => {
            failed = listener;
        },
        onSocket: (listener) => {
            socket = listener;
        },
        close,
    };

    return {
        channel,
        close,
        signal: (payload: unknown) => changed(payload),
        subscribed: () => subscribed(),
        failed: () => failed(),
        socket: (state: SocketState) => socket(state),
    };
}

function fakeTimers() {
    const running = new Map<number, () => void>();
    let next = 1;
    const timers: Timers = {
        every: (callback, ms) => {
            expect(ms).toBe(POLL_MS);
            running.set(next, callback);

            return next++;
        },
        cancel: (handle) => {
            running.delete(handle as number);
        },
    };

    return {
        timers,
        running,
        tick: () => running.forEach((callback) => callback()),
    };
}

describe('watchRoom', () => {
    it('fetches the state on a higher revision only', () => {
        const fake = fakeChannel();
        const { timers } = fakeTimers();
        const refresh = vi.fn();
        const watch = watchRoom({
            revision: 3,
            channel: fake.channel,
            refresh,
            timers,
        });

        fake.signal({ v: 1, revision: 3 });
        fake.signal({ v: 1, revision: 2 });
        expect(refresh).not.toHaveBeenCalled();

        fake.signal({ v: 1, revision: 4 });
        fake.signal({ v: 1, revision: 4 });
        expect(refresh).toHaveBeenCalledTimes(1);

        watch.seen(6);
        fake.signal({ v: 1, revision: 5 });
        expect(refresh).toHaveBeenCalledTimes(1);
    });

    it('drops a signal that is not the revision alone', () => {
        const fake = fakeChannel();
        const { timers } = fakeTimers();
        const refresh = vi.fn();
        watchRoom({ revision: 0, channel: fake.channel, refresh, timers });

        fake.signal({ v: 1, revision: 9, name: 'Aygün' });
        fake.signal({ v: 2, revision: 9 });
        fake.signal('room.changed');
        expect(refresh).not.toHaveBeenCalled();
    });

    it('polls until the channel is subscribed, and fetches once when it is', () => {
        const fake = fakeChannel();
        const { timers, running, tick } = fakeTimers();
        const refresh = vi.fn();
        watchRoom({ revision: 0, channel: fake.channel, refresh, timers });

        // A connected socket is not yet a subscribed channel.
        fake.socket('connected');
        expect(running.size).toBe(1);
        tick();
        expect(refresh).toHaveBeenCalledTimes(1);

        fake.subscribed();
        expect(running.size).toBe(0);
        expect(refresh).toHaveBeenCalledTimes(2);
    });

    it('polls again when the socket drops or the subscription fails, until it is back', () => {
        const fake = fakeChannel();
        const { timers, running } = fakeTimers();
        const refresh = vi.fn();
        const watch = watchRoom({
            revision: 0,
            channel: fake.channel,
            refresh,
            timers,
        });
        fake.subscribed();

        fake.socket('disconnected');
        fake.socket('connecting');
        expect(running.size).toBe(1);

        // Reconnected and subscribed again: whatever was missed is fetched.
        fake.socket('connected');
        expect(running.size).toBe(1);
        fake.subscribed();
        expect(running.size).toBe(0);
        expect(refresh).toHaveBeenCalledTimes(2);

        fake.failed();
        expect(running.size).toBe(1);

        watch.stop();
        expect(running.size).toBe(0);
        expect(fake.close).toHaveBeenCalledOnce();
    });

    it('polls when there is no channel at all', () => {
        const { timers, running, tick } = fakeTimers();
        const refresh = vi.fn();
        watchRoom({ revision: 0, channel: null, refresh, timers });

        expect(running.size).toBe(1);
        tick();
        tick();
        expect(refresh).toHaveBeenCalledTimes(2);
    });
});
