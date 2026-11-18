import { describe, expect, it } from 'vite-plus/test';
import {
    MIN_TAIL_MS,
    MUTE_STORAGE_KEYS,
    readMuted,
    ROOM_SOUNDS,
    roomSoundPlayer,
    SPEAKER_GAIN,
    STOP_MS,
    writeMuted,
} from './sound';
import type {
    AudioBufferLike,
    AudioContextLike,
    AudioSourceLike,
    StorageLike,
} from './sound';

/*
 * A room page's sound player (ADR 025 "Sound", ADR 028, docs/frontend.md
 * rule 15 "Sound"): nothing before a gesture, nothing while the device is
 * muted or the page hidden, its own speaker's files only, a long cue that
 * ends where it is asked to — from its middle when late, not at all when
 * too little is left — every cue stopped when its moment ends, and never a
 * throw where audio is missing, refused or a file fails.
 */

function memoryStorage(): StorageLike & { items: Map<string, string> } {
    const items = new Map<string, string>();

    return {
        items,
        getItem: (key) => items.get(key) ?? null,
        setItem: (key, value) => void items.set(key, value),
        removeItem: (key) => void items.delete(key),
    };
}

const refusing: StorageLike = {
    getItem: () => {
        throw new Error('blocked');
    },
    setItem: () => {
        throw new Error('blocked');
    },
    removeItem: () => {
        throw new Error('blocked');
    },
};

type Started = {
    sound: string;
    when: number;
    offset: number;
    gain: number;
    stopped: boolean;
    /** When it was told to stop, if at a time; how its gain fell. */
    stopAt?: number;
    ramp?: [number, number][];
};

/** How long each fake file lasts, in seconds: the long cues as ADR 028 makes them. */
const DURATIONS: Record<string, number> = {
    countdown: 3,
    clock: 5,
    roll: 2.5,
};

/** A fake Web Audio context: buffers know their sound's name, sources record their start. */
function fakeAudio({ allowed = true }: { allowed?: boolean } = {}) {
    const started: Started[] = [];
    const made: FakeContext[] = [];

    type FakeContext = AudioContextLike & { closed: boolean };

    const create = (): FakeContext => {
        const context: FakeContext = {
            state: 'suspended',
            currentTime: 10,
            destination: {},
            onstatechange: null,
            closed: false,
            resume: () => {
                if (!allowed) {
                    return Promise.reject(new Error('NotAllowedError'));
                }

                context.state = 'running';
                context.onstatechange?.();

                return Promise.resolve();
            },
            close: () => {
                context.closed = true;
                context.state = 'closed';

                return Promise.resolve();
            },
            createBuffer: () => ({ duration: 0 }),
            createGain: () => {
                const ramp: [number, number][] = [];
                const node = {
                    ramp,
                    gain: {
                        value: 1,
                        setValueAtTime: (value: number, time: number) =>
                            ramp.push([value, time]),
                        linearRampToValueAtTime: (
                            value: number,
                            time: number,
                        ) => ramp.push([value, time]),
                    },
                    connect: () => node,
                };

                return node;
            },
            createBufferSource: () => {
                let gain = 1;
                let ramp: [number, number][] = [];
                const source: AudioSourceLike & { record?: Started } = {
                    buffer: null,
                    onended: null,
                    connect: (node) => {
                        const target = node as {
                            gain?: { value: number };
                            ramp?: [number, number][];
                        };
                        gain = target.gain?.value ?? 1;
                        ramp = target.ramp ?? [];

                        return node;
                    },
                    start: (when = 0, offset = 0) => {
                        const name = (source.buffer as { name?: string } | null)
                            ?.name;

                        if (name !== undefined) {
                            source.record = {
                                sound: name,
                                when,
                                offset,
                                gain,
                                stopped: false,
                                ramp,
                            };
                            started.push(source.record);
                        }
                    },
                    stop: (when?: number) => {
                        if (source.record) {
                            source.record.stopped = true;

                            if (when !== undefined) {
                                source.record.stopAt = when;
                            }
                        }
                    },
                };

                return source;
            },
            decodeAudioData: (data) => {
                const name = new TextDecoder().decode(data);

                return Promise.resolve({
                    name,
                    duration: DURATIONS[name] ?? 0.2,
                } as AudioBufferLike);
            },
        };
        made.push(context);

        return context;
    };

    return { started, made, create };
}

const urls = {
    screen: Object.fromEntries(
        ROOM_SOUNDS.screen.map((name) => [name, `/content/${name}.mp3`]),
    ),
    phone: Object.fromEntries(
        ROOM_SOUNDS.phone.map((name) => [name, `/content/${name}.mp3`]),
    ),
};

/** Fetching a file gives its name as bytes; `missing` files fail. */
function loader(missing: string[] = []) {
    const asked: string[] = [];

    return {
        asked,
        load: (url: string) => {
            asked.push(url);
            const name = url.replace('/content/', '').replace('.mp3', '');

            return missing.includes(name)
                ? Promise.reject(new Error('404'))
                : Promise.resolve(new TextEncoder().encode(name).buffer);
        },
    };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('the device mute', () => {
    it('is remembered per speaker on this device, and a refusing storage never throws', () => {
        const storage = memoryStorage();

        expect(readMuted(storage, 'screen')).toBe(false);
        writeMuted(storage, 'screen', true);
        expect(storage.items.get(MUTE_STORAGE_KEYS.screen)).toBe('1');
        expect(readMuted(storage, 'screen')).toBe(true);
        expect(readMuted(storage, 'phone')).toBe(false);
        writeMuted(storage, 'screen', false);
        expect(readMuted(storage, 'screen')).toBe(false);

        expect(readMuted(refusing, 'phone')).toBe(false);
        expect(() => writeMuted(refusing, 'phone', true)).not.toThrow();
        expect(readMuted(null, 'phone')).toBe(false);
        expect(MUTE_STORAGE_KEYS.screen).not.toBe('elifba.board.muted');
    });
});

describe('roomSoundPlayer', () => {
    it('stays locked and silent until a gesture unlocks it, and only then fetches its files', async () => {
        const audio = fakeAudio();
        const files = loader();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: files.load,
        });

        player.play('countdown');
        expect(player.snapshot()).toEqual({ muted: false, locked: true });
        expect(audio.made).toHaveLength(0);
        expect(files.asked).toEqual([]);

        player.unlock();
        await settle();

        expect(player.snapshot()).toEqual({ muted: false, locked: false });
        expect(files.asked).toEqual(
            ROOM_SOUNDS.screen.map((name) => `/content/${name}.mp3`),
        );

        player.play('go');
        expect(audio.started).toEqual([
            {
                sound: 'go',
                when: 10,
                offset: 0,
                gain: SPEAKER_GAIN.screen,
                stopped: false,
                ramp: [],
            },
        ]);
    });

    it("plays only its own speaker's files: a phone has no room moment to play", async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('clock');
        player.play('fanfare');
        player.play('tap');

        expect(audio.started.map(({ sound, gain }) => [sound, gain])).toEqual([
            ['tap', SPEAKER_GAIN.phone],
        ]);
    });

    it('plays nothing while muted, makes no audio for a muted device, and mutes what was scheduled', async () => {
        const audio = fakeAudio();
        const storage = memoryStorage();
        writeMuted(storage, 'screen', true);
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: () => storage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        expect(audio.made).toHaveLength(0);
        expect(player.snapshot().muted).toBe(true);

        // Turning it on is a gesture: it unlocks.
        player.setMuted(false);
        await settle();
        expect(readMuted(storage, 'screen')).toBe(false);
        expect(player.snapshot()).toEqual({ muted: false, locked: false });

        player.play('fanfare', { at: 3000 });
        player.setMuted(true);
        player.play('go');

        expect(
            audio.started.map(({ sound, stopped }) => [sound, stopped]),
        ).toEqual([['fanfare', true]]);
        expect(readMuted(storage, 'screen')).toBe(true);
    });

    it('ends a long cue where it is asked to, starting it from its middle when the page is late', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('roll', { at: 3100, ends: true });
        player.play('roll', { at: 600, ends: true });
        // The countdown heard 1.2 s late: its last 1.8 s, ending at `opens_ms`.
        player.play('countdown', { at: 1800, ends: true });
        // The clock heard ahead: it starts 0.4 s from now and ends at the bell.
        player.play('clock', { at: 5400, ends: true });

        const round = (value: number) => Math.round(value * 1000) / 1000;

        expect(
            audio.started.map(({ sound, when, offset }) => [
                sound,
                round(when),
                round(offset),
            ]),
        ).toEqual([
            ['roll', 10.6, 0],
            ['roll', 10, 1.9],
            ['countdown', 10, 1.2],
            ['clock', 10.4, 0],
        ]);
    });

    it('plays nothing of a long cue with less than a quarter second of it left', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('countdown', { at: MIN_TAIL_MS - 10, ends: true });
        player.play('clock', { at: 0, ends: true });
        player.play('roll', { at: -100, ends: true });
        player.play('countdown', { at: MIN_TAIL_MS + 10, ends: true });

        expect(
            audio.started.map(({ sound, offset }) => [
                sound,
                Math.round(offset * 1000),
            ]),
        ).toEqual([['countdown', 3000 - MIN_TAIL_MS - 10]]);
    });

    it('stops every cue of a moment that ended: one scheduled never plays, one sounding fades out at once', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        // The round closed early: the clock was sounding, the ranking of another moment was not due yet.
        player.play('clock', { at: 2600, ends: true });
        player.play('ranking', { at: 420 });
        audio.made[0].currentTime = 10.2;
        player.cancel();

        const [clock, ranking] = audio.started;

        expect(clock.stopAt).toBeCloseTo(10.2 + STOP_MS / 1000, 6);
        expect(clock.ramp).toEqual([
            [SPEAKER_GAIN.screen, 10.2],
            [0, 10.2 + STOP_MS / 1000],
        ]);
        expect(ranking.stopped).toBe(true);
        expect(ranking.stopAt).toBeUndefined();

        // A cue played after the stop belongs to the next moment and plays.
        player.play('close');
        expect(audio.started.at(-1)).toMatchObject({
            sound: 'close',
            stopped: false,
        });
    });

    it('stops at once when the page is hidden and plays nothing until it is shown again', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('fanfare');
        player.hide();
        player.play('close');

        expect(
            audio.started.map(({ sound, stopped }) => [sound, stopped]),
        ).toEqual([['fanfare', true]]);

        player.resume();
        player.play('close');
        expect(audio.started.map(({ sound }) => sound)).toEqual([
            'fanfare',
            'close',
        ]);
    });

    it('never throws where there is no audio, the browser refuses it or a file fails', async () => {
        const none = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: () => refusing,
            createContext: () => null,
            load: loader().load,
        });

        expect(() => {
            none.unlock();
            none.play('countdown', { at: 3000, ends: true });
            none.setMuted(true);
            none.setMuted(false);
            none.hide();
            none.resume();
            none.cancel();
            none.release();
        }).not.toThrow();
        expect(none.snapshot().locked).toBe(true);

        const refused = fakeAudio({ allowed: false });
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: refused.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('go');
        expect(player.snapshot().locked).toBe(true);
        expect(refused.started).toEqual([]);

        const audio = fakeAudio();
        const partial = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader(['sent']).load,
        });

        partial.unlock();
        await settle();
        partial.play('sent');
        partial.play('tap');
        expect(audio.started.map(({ sound }) => sound)).toEqual(['tap']);
    });

    it('closes its audio when the page goes, and a next gesture opens it again', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.release();

        expect(audio.made[0].closed).toBe(true);
        expect(player.snapshot().locked).toBe(true);

        player.unlock();
        await settle();
        player.play('tap');
        expect(audio.made).toHaveLength(2);
        expect(audio.started.map(({ sound }) => sound)).toEqual(['tap']);
    });
});
