/**
 * The rooms whose live voice this device left or declined (docs/frontend.md
 * rule 15 "Live voice", "Voice on by default"; ADR 031 decision 40 as
 * amended 2026-11-19). «Səsdən çıx» and «İndi yox» are remembered for that
 * room on this device, and a page never joins that room's voice by itself
 * again: only the player's own «Səsə qoşul» takes the room off the list.
 *
 * The list is the ids of the last twenty such rooms, in `localStorage`
 * under `elifba.rooms.voice.out`, and in the page's memory beside it. Every
 * read and write of the storage is inside `try`/`catch`: a browser whose
 * storage is unavailable, full or refused keeps the list for the life of
 * the document — an Inertia visit keeps it — and forgets it at a reload,
 * after which the page joins by itself again. Nothing of it is ever sent.
 * Pure but for the storage it is given, so a test hands it a fake one.
 */

export const VOICE_OUT_KEY = 'elifba.rooms.voice.out';

/** How many rooms the device remembers; the oldest goes first. */
export const VOICE_OUT_KEEP = 20;

/** The two calls of `Storage` this needs; either may throw. */
export type VoiceOutStorage = Pick<Storage, 'getItem' | 'setItem'>;

export class VoiceOut {
    /** The rooms in the order they were left, the latest last; read from the storage once. */
    private ids: string[] | null = null;
    private readonly listeners = new Set<() => void>();
    private readonly storage: () => VoiceOutStorage | null;

    constructor(storage: () => VoiceOutStorage | null) {
        this.storage = storage;
    }

    /** Whether this device left or declined the room's voice. */
    has(room: string): boolean {
        return this.list().includes(room);
    }

    /** «Səsdən çıx» or «İndi yox»: the room goes to the end of the list, and the list keeps its last twenty. */
    leave(room: string): void {
        const next = [...this.list().filter((id) => id !== room), room].slice(
            -VOICE_OUT_KEEP,
        );
        this.change(next);
    }

    /** The player's own «Səsə qoşul»: the room comes off the list. */
    back(room: string): void {
        if (this.has(room)) {
            this.change(this.list().filter((id) => id !== room));
        }
    }

    /** For `useSyncExternalStore`: told whenever the list changes on this page. */
    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    private list(): string[] {
        this.ids ??= this.read();

        return this.ids;
    }

    private change(next: string[]): void {
        this.ids = next;

        try {
            this.storage()?.setItem(VOICE_OUT_KEY, JSON.stringify(next));
        } catch {
            // Kept in the page's memory until a reload.
        }

        this.listeners.forEach((listener) => listener());
    }

    private read(): string[] {
        try {
            const raw = this.storage()?.getItem(VOICE_OUT_KEY) ?? null;
            const parsed: unknown = raw === null ? [] : JSON.parse(raw);

            return Array.isArray(parsed)
                ? parsed
                      .filter(
                          (id): id is string =>
                              typeof id === 'string' && id.length > 0,
                      )
                      .slice(-VOICE_OUT_KEEP)
                : [];
        } catch {
            return [];
        }
    }
}

/** The device's `localStorage`, or null where even reaching it throws (a refused or sandboxed storage). */
export function deviceStorage(): VoiceOutStorage | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
}

/** The page's one list: an Inertia visit to another room keeps it. */
export const voiceOut = new VoiceOut(deviceStorage);
