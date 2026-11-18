/**
 * A room game's sound (docs/architecture.md §12 "Room content", ADR 025
 * "Sound", docs/frontend.md rule 15 "Sound"): the screen is the room's
 * speaker and plays the moments everybody shares; a phone plays only quiet
 * cues of its own. A page is sent the URLs of its own speaker's files in
 * its props (`sounds`) and plays nothing else. When a cue plays is
 * `roomCues()`'s (`components/rooms/sound-cues.ts`); this module only
 * plays one.
 *
 * Browsers refuse sound before a gesture: the player makes its audio
 * context in one (`unlock()`) and only then fetches and decodes its files.
 * Each device has its own mute, kept in `localStorage` under its
 * speaker's key — never the account's, never sent anywhere. Where audio is
 * missing, refused or a file fails, nothing throws: the game plays on in
 * silence.
 */

/** Each speaker's sounds, as `content/rooms/sounds.json` and `RoomSound` name them. */
export const ROOM_SOUNDS = {
    screen: [
        'count',
        'study',
        'question',
        'tick',
        'close',
        'ranking',
        'roll',
        'fanfare',
    ],
    phone: ['tap', 'undo', 'sent', 'right', 'wrong'],
} as const;

export type RoomSpeaker = keyof typeof ROOM_SOUNDS;
export type ScreenSound = (typeof ROOM_SOUNDS.screen)[number];
export type PhoneSound = (typeof ROOM_SOUNDS.phone)[number];
export type RoomSoundName = ScreenSound | PhoneSound;

/** A page's `sounds` prop: its speaker's files by name, from the server. */
export type RoomSoundUrls = Partial<Record<RoomSoundName, string>>;

/** The device's own choice, one key per speaker: muting the screen leaves a phone page alone. */
export const MUTE_STORAGE_KEYS: Record<RoomSpeaker, string> = {
    screen: 'elifba.rooms.screen.muted',
    phone: 'elifba.rooms.phone.muted',
};

/**
 * How loud each device plays the files, which share one loudness: the
 * screen at full level, a phone at half (−6 dB), so ten phones in one
 * room stay quiet under it.
 */
export const SPEAKER_GAIN: Record<RoomSpeaker, number> = {
    screen: 1,
    phone: 0.5,
};

export type StorageLike = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

/** Whether this device keeps the speaker silent; false when storage cannot say. */
export function readMuted(
    storage: StorageLike | null,
    speaker: RoomSpeaker,
): boolean {
    try {
        return storage?.getItem(MUTE_STORAGE_KEYS[speaker]) === '1';
    } catch {
        return false;
    }
}

/** Remembers the choice on this device; a refusal only means it is not remembered. */
export function writeMuted(
    storage: StorageLike | null,
    speaker: RoomSpeaker,
    muted: boolean,
): void {
    try {
        if (muted) {
            storage?.setItem(MUTE_STORAGE_KEYS[speaker], '1');
        } else {
            storage?.removeItem(MUTE_STORAGE_KEYS[speaker]);
        }
    } catch {
        // Not remembered: the page stays as the toggle left it.
    }
}

/** The page's `localStorage`, or null where even reaching it throws. */
export function deviceStorage(): StorageLike | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null;
    }
}

/** What the Web Audio API gives the player: only the parts it uses. */
export type AudioBufferLike = { duration: number };

export type AudioSourceLike = {
    buffer: AudioBufferLike | null;
    onended: (() => void) | null;
    connect: (node: AudioNodeLike) => unknown;
    start: (when?: number, offset?: number) => void;
    stop: (when?: number) => void;
};

export type AudioNodeLike = {
    connect?: (node: AudioNodeLike) => unknown;
};

export type AudioContextLike = {
    state: string;
    currentTime: number;
    destination: AudioNodeLike;
    onstatechange: (() => void) | null;
    resume: () => Promise<void>;
    close: () => Promise<void>;
    createBufferSource: () => AudioSourceLike;
    createGain: () => AudioNodeLike & {
        gain: { value: number };
        connect: (node: AudioNodeLike) => unknown;
    };
    createBuffer: (
        channels: number,
        length: number,
        rate: number,
    ) => AudioBufferLike;
    decodeAudioData: (data: ArrayBuffer) => Promise<AudioBufferLike>;
};

export type RoomSoundState = {
    /** This device's choice. */
    muted: boolean;
    /** No sound yet: the browser waits for a gesture (or has no audio at all). */
    locked: boolean;
};

/** A cue as `roomCues()` gives it: when it starts, in ms from now, or when it ends. */
export type PlayAt = { at?: number; ends?: boolean };

export type RoomSoundPlayer = {
    subscribe: (listener: () => void) => () => void;
    /** The same object until something changes, for `useSyncExternalStore`. */
    snapshot: () => RoomSoundState;
    /** Called inside a gesture: makes and resumes the audio context, then fetches the files. */
    unlock: () => void;
    /** The mute toggle (a gesture: turning sound on also unlocks it). */
    setMuted: (muted: boolean) => void;
    play: (sound: RoomSoundName, when?: PlayAt) => void;
    /** Drops the cues scheduled for later (the moment they belonged to is over). */
    cancel: () => void;
    /** After the page was hidden: wakes a context the browser suspended, where it lets it. */
    resume: () => void;
    /** The page is gone: the context is closed; the decoded files stay for a next one. */
    release: () => void;
};

type Scheduled = { source: AudioSourceLike; startsAt: number };

/**
 * One page's sound. `createContext` and `load` come from outside, so
 * Vitest gives fakes; in the browser they are the Web Audio API's
 * `AudioContext` and `fetch`.
 */
export function roomSoundPlayer({
    speaker,
    urls,
    storage = deviceStorage,
    createContext = browserContext,
    load = fetchBytes,
}: {
    speaker: RoomSpeaker;
    urls: RoomSoundUrls;
    storage?: () => StorageLike | null;
    createContext?: () => AudioContextLike | null;
    load?: (url: string) => Promise<ArrayBuffer>;
}): RoomSoundPlayer {
    const listeners = new Set<() => void>();
    const buffers = new Map<RoomSoundName, AudioBufferLike>();
    const scheduled = new Set<Scheduled>();
    let context: AudioContextLike | null = null;
    let loading = false;
    let muted = readMuted(storage(), speaker);
    let state: RoomSoundState = { muted, locked: true };

    const running = () => context !== null && context.state === 'running';

    const emit = () => {
        const next = { muted, locked: !running() };

        if (next.muted !== state.muted || next.locked !== state.locked) {
            state = next;
            listeners.forEach((listener) => listener());
        }
    };

    const fetchAll = () => {
        if (loading || context === null) {
            return;
        }

        loading = true;
        const decoder = context;

        for (const name of ROOM_SOUNDS[speaker] as readonly RoomSoundName[]) {
            const url = urls[name];

            if (url === undefined || buffers.has(name)) {
                continue;
            }

            void load(url)
                .then((bytes) => decoder.decodeAudioData(bytes))
                .then((buffer) => {
                    buffers.set(name, buffer);
                })
                .catch(() => {
                    // That cue stays silent.
                });
        }
    };

    const stopScheduled = (all: boolean) => {
        const now = context?.currentTime ?? 0;

        for (const cue of scheduled) {
            if (all || cue.startsAt > now) {
                try {
                    cue.source.stop();
                } catch {
                    // Already stopped.
                }

                scheduled.delete(cue);
            }
        }
    };

    const unlock = () => {
        if (muted) {
            return;
        }

        try {
            if (context === null) {
                context = createContext();

                if (context === null) {
                    return;
                }

                context.onstatechange = emit;
            }

            const audio = context;

            if (audio.state !== 'running') {
                void audio
                    .resume()
                    .catch(() => null)
                    .then(emit);
            }

            // iOS unlocks on a sound started inside the gesture: one silent sample.
            const silence = audio.createBufferSource();
            silence.buffer = audio.createBuffer(1, 1, 22050);
            silence.connect(audio.destination);
            silence.start(0);
        } catch {
            // No sound on this device: the game plays on in silence.
        }

        fetchAll();
        emit();
    };

    return {
        subscribe: (listener) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },
        snapshot: () => state,
        unlock,
        setMuted: (next) => {
            muted = next;
            writeMuted(storage(), speaker, next);

            if (next) {
                stopScheduled(true);
            } else {
                unlock();
            }

            emit();
        },
        play: (sound, { at = 0, ends = false } = {}) => {
            const buffer = buffers.get(sound);

            if (muted || !running() || context === null || !buffer) {
                return;
            }

            try {
                const audio = context;
                const source = audio.createBufferSource();
                const gain = audio.createGain();
                let wait = at / 1000;
                let offset = 0;

                if (ends) {
                    // It ends at `at`: started earlier, or from its middle when there is no time.
                    wait -= buffer.duration;

                    if (wait < 0) {
                        offset = -wait;
                        wait = 0;
                    }
                }

                if (offset >= buffer.duration) {
                    return;
                }

                source.buffer = buffer;
                gain.gain.value = SPEAKER_GAIN[speaker];
                source.connect(gain);
                gain.connect(audio.destination);

                const cue = { source, startsAt: audio.currentTime + wait };
                scheduled.add(cue);
                source.onended = () => scheduled.delete(cue);
                source.start(cue.startsAt, offset);
            } catch {
                // A cue that cannot play is skipped.
            }
        },
        cancel: () => stopScheduled(false),
        resume: () => {
            if (muted || context === null || running()) {
                return;
            }

            void context
                .resume()
                .catch(() => null)
                .then(emit);
        },
        release: () => {
            stopScheduled(true);

            if (context !== null) {
                const audio = context;
                context = null;
                loading = false;
                audio.onstatechange = null;
                void audio.close().catch(() => null);
            }

            emit();
        },
    };
}

/** The browser's audio context, or null where there is none or it refuses. */
function browserContext(): AudioContextLike | null {
    try {
        const Context =
            typeof window === 'undefined'
                ? undefined
                : (window.AudioContext ??
                  (window as { webkitAudioContext?: typeof AudioContext })
                      .webkitAudioContext);

        return Context === undefined
            ? null
            : (new Context() as unknown as AudioContextLike);
    } catch {
        return null;
    }
}

/** A file from this host (a `/content/…` URL from the props). */
function fetchBytes(url: string): Promise<ArrayBuffer> {
    return fetch(url, { credentials: 'same-origin' }).then((response) => {
        if (!response.ok) {
            throw new Error(`${response.status}`);
        }

        return response.arrayBuffer();
    });
}
