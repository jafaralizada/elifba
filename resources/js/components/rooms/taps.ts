import type { RoomAsk } from '@/rooms/contract';

/** What a tap leaves of the answer being made, and whether it is now sent. */
export type Tapped = { ids: string[]; send: boolean };

/**
 * A tap on an item of the question (docs/frontend.md rule 15, "Taps"). A
 * `sequence` adds the item to the end, once; «Geri» takes back the last
 * (`untap()`). A `set` adds a cell, and a second tap on it takes it back.
 * A `choice` is one tap. Whichever the shape, the tap that makes `pick`
 * items sends the answer — one attempt, all or nothing — and nothing here
 * says whether it is right.
 */
export function tap(ask: RoomAsk, picked: string[], id: string): Tapped {
    if (!ask.items.some((item) => item.id === id)) {
        return { ids: picked, send: false };
    }

    if (picked.includes(id)) {
        return {
            ids:
                ask.shape === 'set'
                    ? picked.filter((other) => other !== id)
                    : picked,
            send: false,
        };
    }

    const ids = [...picked, id];

    return { ids, send: ids.length === ask.pick };
}

/** «Geri»: the last tap taken back. */
export function untap(picked: string[]): string[] {
    return picked.slice(0, -1);
}
