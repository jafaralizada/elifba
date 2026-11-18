import { describe, expect, it, vi } from 'vite-plus/test';
import type { RoomChannel, SocketState, Timers } from './live';
import { oneAtATime, POLL_MS, watchRoom } from './live';

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

    it('tells a page that polls by itself whether it hears the channel, and never polls', () => {
        const fake = fakeChannel();
        const { timers, running } = fakeTimers();
        const refresh = vi.fn();
        const heard: boolean[] = [];
        const watch = watchRoom({
            revision: 0,
            channel: fake.channel,
            refresh,
            timers,
            onListening: (listening) => heard.push(listening),
        });

        expect(heard).toEqual([false]);
        fake.subscribed();
        expect(heard).toEqual([false, true]);
        expect(refresh).toHaveBeenCalledOnce();

        fake.socket('disconnected');
        fake.socket('connecting');
        fake.failed();
        expect(heard).toEqual([false, true, false]);
        fake.subscribed();
        expect(heard).toEqual([false, true, false, true]);
        expect(running.size).toBe(0);

        watch.stop();
        fake.socket('disconnected');
        expect(heard).toEqual([false, true, false, true]);
    });
});

describe('oneAtATime', () => {
    it('folds the calls made while a request is in flight into one more', async () => {
        const ends: Array<() => void> = [];
        const request = vi.fn(
            () => new Promise<void>((resolve) => ends.push(resolve)),
        );
        const run = oneAtATime(request);

        run();
        run();
        run();
        run();
        expect(request).toHaveBeenCalledTimes(1);

        ends[0]();
        await vi.waitFor(() => expect(request).toHaveBeenCalledTimes(2));

        ends[1]();
        await Promise.resolve();
        await Promise.resolve();
        expect(request).toHaveBeenCalledTimes(2);

        run();
        expect(request).toHaveBeenCalledTimes(3);
    });

    it('goes on after a request that failed', async () => {
        const request = vi
            .fn<() => Promise<void>>()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValue(undefined);
        const run = oneAtATime(request);

        run();
        run();
        await vi.waitFor(() => expect(request).toHaveBeenCalledTimes(2));
    });
});
