/**
 * A room game's sound (docs/architecture.md §12 "Room content", ADR 025
 * "Sound", ADR 028, docs/frontend.md rule 15 "Sound"): the screen is the room's
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
 *
 * A cue belongs to its moment (ADR 028 decision 7): when the moment ends
 * (`cancel()`) or the page is hidden (`hide()`), every cue stops at once —
 * one not yet started is dropped, one sounding fades out within `STOP_MS`.
 * A long cue placed to end at a moment (`ends`) starts from its middle when
 * the page is late, and not at all when less than `MIN_TAIL_MS` is left.
 *
 * The screen's one voice (ADR 030, `say()`), as a home board ends a game:
 * the clips of the final's first place's first names, fetched by ids from
 * `GET /rooms/{room}/members/{member}/voice`, then the board's end phrase
 * («Əla oynadın!», «Əla oynadınız!»), fetched when the page hears the
 * final begin and its sound is on, kept decoded in memory only, and said
 * one after another after the fanfare — those that arrived in time; a
 * phone says none.
 */

/** Each speaker's sounds, as `content/rooms/sounds.json` and `RoomSound` name them. */
export const ROOM_SOUNDS = {
    screen: [
        'countdown',
        'go',
        'study',
        'question',
        'clock',
        'close',
        'ranking',
        'place',
        'roll',
        'fanfare',
        'join',
    ],
    phone: ['tap', 'undo', 'sent', 'right', 'wrong'],
} as const;

export type RoomSpeaker = keyof typeof ROOM_SOUNDS;
export type ScreenSound = (typeof ROOM_SOUNDS.screen)[number];
export type PhoneSound = (typeof ROOM_SOUNDS.phone)[number];
export type RoomSoundName = ScreenSound | PhoneSound;

/** The screen's `finish` prop (ADR 030): «Əla oynadın!» and «Əla oynadınız!», public clips, or null. */
export type RoomFinishVoice = { one: string | null; many: string | null };

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

/** How quickly a cue still sounding when its moment ends goes silent: at once, without a click. */
export const STOP_MS = 40;

/** A cue placed to end at a moment plays nothing when less than this is left of it: a fragment is noise. */
export const MIN_TAIL_MS = 250;

/**
 * The voice's level beside the room's files (ADR 030): the name and phrase
 * clips are levelled to −16 LUFS, the room's set to −20 LUFS, so the voice
 * plays 3.6 dB under the screen's level and sits with the effects.
 */
export const VOICE_GAIN = 0.66;

/** The pause between two names of a shared first place. */
export const NAME_GAP_MS = 300;

/** The pause between the last name and the phrase: the board's (`NAME_GAP_MS` of `voice.ts`, ADR 016 decision 4). */
export const PHRASE_GAP_MS = 150;

/**
 * How long before its time the screen settles which names it says: a clip
 * that has not arrived by then is skipped, and one arriving later dropped.
 */
export const NAME_LEAD_MS = 250;

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
        gain: {
            value: number;
            setValueAtTime: (value: number, time: number) => unknown;
            linearRampToValueAtTime: (value: number, time: number) => unknown;
        };
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
    /** The moment the cues belonged to is over: drops those scheduled, fades out those sounding. */
    cancel: () => void;
    /** The page is hidden: everything stops, and nothing plays until `resume()`. */
    hide: () => void;
    /** The page is shown again: wakes a context the browser suspended, where it lets it. */
    resume: () => void;
    /** The page is gone: the context is closed; the decoded files stay for a next one. */
    release: () => void;
    /**
     * The screen's cheer (ADR 030): fetches the clips of `names` and of
     * `phrase` now and, `at` ms from now, says those that arrived — the
     * names one after another, then the phrase; nothing on a phone, while
     * muted, hidden or locked. They stop with the moment.
     */
    say: (names: string[], phrase: string | null, at: number) => void;
};

/** Runs `run` in `ms`; the function it gives cancels it. */
export type Later = (run: () => void, ms: number) => () => void;

type Scheduled = {
    source: AudioSourceLike;
    gain: ReturnType<AudioContextLike['createGain']>;
    level: number;
    startsAt: number;
};

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
    later = browserLater,
}: {
    speaker: RoomSpeaker;
    urls: RoomSoundUrls;
    storage?: () => StorageLike | null;
    createContext?: () => AudioContextLike | null;
    load?: (url: string) => Promise<ArrayBuffer>;
    later?: Later;
}): RoomSoundPlayer {
    const listeners = new Set<() => void>();
    const buffers = new Map<RoomSoundName, AudioBufferLike>();
    const scheduled = new Set<Scheduled>();
    /** The names waiting for their time: each one's cancel. */
    const sayings = new Set<() => void>();
    let context: AudioContextLike | null = null;
    let loading = false;
    let hidden = false;
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

    /** Every cue stops: one not yet started never plays, one sounding fades out within STOP_MS. */
    const stopAll = () => {
        const now = context?.currentTime ?? 0;
        const end = now + STOP_MS / 1000;

        // Names not yet due are dropped with their clips.
        sayings.forEach((cancel) => cancel());
        sayings.clear();

        for (const cue of scheduled) {
            try {
                if (cue.startsAt > now) {
                    cue.source.stop();
                } else {
                    cue.gain.gain.setValueAtTime(cue.level, now);
                    cue.gain.gain.linearRampToValueAtTime(0, end);
                    cue.source.stop(end);
                }
            } catch {
                // Already stopped.
            }

            scheduled.delete(cue);
        }
    };

    /** Starts `buffer` at `startsAt` on the context's clock, from `offset`, at the speaker's level times `factor`. */
    const start = (
        audio: AudioContextLike,
        buffer: AudioBufferLike,
        startsAt: number,
        offset: number,
        factor: number,
    ) => {
        const source = audio.createBufferSource();
        const gain = audio.createGain();
        const level = SPEAKER_GAIN[speaker] * factor;
        source.buffer = buffer;
        gain.gain.value = level;
        source.connect(gain);
        gain.connect(audio.destination);

        const cue = { source, gain, level, startsAt };
        scheduled.add(cue);
        source.onended = () => scheduled.delete(cue);
        source.start(startsAt, offset);
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
                stopAll();
            } else {
                unlock();
            }

            emit();
        },
        play: (sound, { at = 0, ends = false } = {}) => {
            const buffer = buffers.get(sound);

            if (muted || hidden || !running() || context === null || !buffer) {
                return;
            }

            try {
                const audio = context;
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

                if (ends && buffer.duration - offset < MIN_TAIL_MS / 1000) {
                    return;
                }

                start(audio, buffer, audio.currentTime + wait, offset, 1);
            } catch {
                // A cue that cannot play is skipped.
            }
        },
        cancel: stopAll,
        hide: () => {
            hidden = true;
            stopAll();
        },
        resume: () => {
            hidden = false;

            if (muted || context === null || running()) {
                return;
            }

            void context
                .resume()
                .catch(() => null)
                .then(emit);
        },
        say: (names, phrase, at) => {
            const urls = phrase === null ? names : [...names, phrase];

            if (
                speaker !== 'screen' ||
                urls.length === 0 ||
                muted ||
                hidden ||
                !running() ||
                context === null
            ) {
                return;
            }

            const audio = context;
            const due = audio.currentTime + Math.max(0, at) / 1000;
            const clips: (AudioBufferLike | null)[] = urls.map(() => null);
            let settled = false;

            urls.forEach((url, index) => {
                void load(url)
                    .then((bytes) =>
                        settled ? null : audio.decodeAudioData(bytes),
                    )
                    .then((clip) => {
                        if (!settled && clip !== null) {
                            clips[index] = clip;
                        }
                    })
                    .catch(() => {
                        // That clip stays silent: a name off the list, or the network.
                    });
            });

            let cancelLater = () => {};
            const cancel = () => {
                settled = true;
                cancelLater();
            };

            sayings.add(cancel);
            cancelLater = later(
                () => {
                    sayings.delete(cancel);
                    settled = true;

                    if (muted || hidden || !running() || context !== audio) {
                        return;
                    }

                    let startsAt = Math.max(due, audio.currentTime);
                    let said = false;

                    clips.forEach((clip, index) => {
                        if (clip === null) {
                            // Not here in time: skipped.
                            return;
                        }

                        const isPhrase =
                            phrase !== null && index === names.length;

                        if (said) {
                            startsAt +=
                                (isPhrase ? PHRASE_GAP_MS : NAME_GAP_MS) / 1000;
                        }

                        try {
                            start(audio, clip, startsAt, 0, VOICE_GAIN);
                        } catch {
                            // A clip that cannot play is skipped.
                        }

                        startsAt += clip.duration;
                        said = true;
                    });
                },
                Math.max(0, at - NAME_LEAD_MS),
            );
        },
        release: () => {
            stopAll();

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

/** The browser's timer. */
function browserLater(run: () => void, ms: number): () => void {
    const timer = setTimeout(run, ms);

    return () => clearTimeout(timer);
}

/** A file from this host (a `/content/…` URL from the props, or a name clip's route by ids). */
function fetchBytes(url: string): Promise<ArrayBuffer> {
    return fetch(url, { credentials: 'same-origin' }).then((response) => {
        if (!response.ok) {
            throw new Error(`${response.status}`);
        }

        return response.arrayBuffer();
    });
}
