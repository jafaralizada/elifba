/**
 * A microphone open before a reload is open after it (docs/frontend.md rule
 * 15 "Live voice", "A reload keeps it open"; ADR 031 decision 46). When a
 * room's page goes away with its microphone open, the device notes that
 * room and the moment, in `localStorage` under `elifba.rooms.voice.reopen`;
 * the same room's page, loaded again within `REOPEN_MS`, takes the note —
 * once — and opens the microphone as it joins by itself. Shown again
 * without a reload, the page forgets it. One room at a time; every read and
 * write inside `try`/`catch`, so a browser without storage simply starts
 * closed; nothing of it is ever sent. Pure but for the storage and the
 * clock it is given.
 */

export const VOICE_REOPEN_KEY = 'elifba.rooms.voice.reopen';

/** How long after the page went away a reload still opens the microphone: a reload is seconds. */
export const REOPEN_MS = 60_000;

export type VoiceReopenStorage = Pick<
    Storage,
    'getItem' | 'setItem' | 'removeItem'
>;

export class VoiceReopen {
    private readonly storage: () => VoiceReopenStorage | null;
    private readonly now: () => number;

    constructor(storage: () => VoiceReopenStorage | null, now = Date.now) {
        this.storage = storage;
        this.now = now;
    }

    /** The page goes away with its microphone open. */
    remember(room: string): void {
        try {
            this.storage()?.setItem(
                VOICE_REOPEN_KEY,
                JSON.stringify({ room, at: this.now() }),
            );
        } catch {
            // No storage: the reloaded page starts closed.
        }
    }

    /** The page is shown again without a reload, or its microphone was closed: nothing to carry over. */
    forget(room: string): void {
        if (this.read()?.room === room) {
            this.clear();
        }
    }

    /** At a load: whether this room's microphone was open a moment ago. The note goes either way. */
    take(room: string): boolean {
        const note = this.read();

        if (note === null) {
            return false;
        }

        this.clear();
        const age = this.now() - note.at;

        return note.room === room && age >= 0 && age <= REOPEN_MS;
    }

    private read(): { room: string; at: number } | null {
        try {
            const raw = this.storage()?.getItem(VOICE_REOPEN_KEY) ?? null;
            const note: unknown = raw === null ? null : JSON.parse(raw);

            return typeof note === 'object' &&
                note !== null &&
                'room' in note &&
                'at' in note &&
                typeof note.room === 'string' &&
                typeof note.at === 'number'
                ? { room: note.room, at: note.at }
                : null;
        } catch {
            return null;
        }
    }

    private clear(): void {
        try {
            this.storage()?.removeItem(VOICE_REOPEN_KEY);
        } catch {
            // Gone with the page's storage anyway.
        }
    }
}

function deviceStorage(): VoiceReopenStorage | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
}

/** The page's one note. */
export const voiceReopen = new VoiceReopen(deviceStorage);
