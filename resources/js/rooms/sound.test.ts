import { describe, expect, it } from 'vite-plus/test';
import {
    MUTE_STORAGE_KEYS,
    readMuted,
    ROOM_SOUNDS,
    roomSoundPlayer,
    SPEAKER_GAIN,
    writeMuted,
} from './sound';
import type {
    AudioBufferLike,
    AudioContextLike,
    AudioSourceLike,
    StorageLike,
} from './sound';

/*
 * A room page's sound player (ADR 025 "Sound", docs/frontend.md rule 15
 * "Sound"): nothing before a gesture, nothing while the device is muted,
 * its own speaker's files only, a cue that ends where it is asked to, and
 * never a throw where audio is missing, refused or a file fails.
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
                const node = { gain: { value: 1 }, connect: () => node };

                return node;
            },
            createBufferSource: () => {
                let gain = 1;
                const source: AudioSourceLike & { record?: Started } = {
                    buffer: null,
                    onended: null,
                    connect: (node) => {
                        gain =
                            (node as { gain?: { value: number } }).gain
                                ?.value ?? 1;

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
                            };
                            started.push(source.record);
                        }
                    },
                    stop: () => {
                        if (source.record) {
                            source.record.stopped = true;
                        }
                    },
                };

                return source;
            },
            decodeAudioData: (data) =>
                Promise.resolve({
                    name: new TextDecoder().decode(data),
                    duration:
                        new TextDecoder().decode(data) === 'roll' ? 2.4 : 0.2,
                } as AudioBufferLike),
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

        player.play('count');
        expect(player.snapshot()).toEqual({ muted: false, locked: true });
        expect(audio.made).toHaveLength(0);
        expect(files.asked).toEqual([]);

        player.unlock();
        await settle();

        expect(player.snapshot()).toEqual({ muted: false, locked: false });
        expect(files.asked).toEqual(
            ROOM_SOUNDS.screen.map((name) => `/content/${name}.mp3`),
        );

        player.play('count');
        expect(audio.started).toEqual([
            {
                sound: 'count',
                when: 10,
                offset: 0,
                gain: SPEAKER_GAIN.screen,
                stopped: false,
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
        player.play('tick');
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
        player.play('count');

        expect(
            audio.started.map(({ sound, stopped }) => [sound, stopped]),
        ).toEqual([['fanfare', true]]);
        expect(readMuted(storage, 'screen')).toBe(true);
    });

    it('ends a cue where it is asked to, starting it from its middle when there is no time', async () => {
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

        const round = (value: number) => Math.round(value * 1000) / 1000;

        expect(
            audio.started.map(({ when, offset }) => [
                round(when),
                round(offset),
            ]),
        ).toEqual([
            [10.7, 0],
            [10, 1.8],
        ]);
    });

    it('drops the cues scheduled for a moment that ended, and lets one already sounding ring', async () => {
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
        player.play('close');
        player.play('ranking', { at: 420 });
        player.cancel();

        expect(
            audio.started.map(({ sound, stopped }) => [sound, stopped]),
        ).toEqual([
            ['close', false],
            ['ranking', true],
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
            none.play('count');
            none.setMuted(true);
            none.setMuted(false);
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
        player.play('count');
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
