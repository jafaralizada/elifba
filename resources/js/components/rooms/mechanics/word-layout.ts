import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * How the word games are laid out and how their reveal reads (docs/
 * frontend.md rule 15, architecture §12 "Room games"), as plain
 * functions. The letters and the spellings are drawn as the server sent
 * them — cased there, shuffled there — and nothing here judges: whether
 * the viewer's word was right is `mine.correct`, the server's word.
 */

/**
 * The rows of an anagram's tiles: as few rows as `perRow` allows, the
 * tiles shared out evenly, so eight letters stand four over four rather
 * than five over three and no tile stands alone on a row.
 */
export function tileRows(
    count: number,
    perRow: number,
): { rows: number; perRow: number } {
    if (count <= 0) {
        return { rows: 0, perRow: 0 };
    }

    const rows = Math.ceil(count / Math.max(1, perRow));

    return { rows, perRow: Math.ceil(count / rows) };
}

/** The text size of a letter in the word's places: smaller only when a row of places is too narrow for it. */
export function slotText(places: number): 'text-2xl' | 'text-xl' {
    return places <= 9 ? 'text-2xl' : 'text-xl';
}

/** The letters of a list of ids, as the question's tiles write them. */
export function lettersOf(ask: RoomAsk, ids: string[]): string[] {
    const letters = new Map(ask.items.map((item) => [item.id, item.text]));

    return ids.map((id) => letters.get(id) ?? '');
}

/**
 * What an anagram's reveal says of the viewer's word:
 * - `same`: right, and the round's word — one row, «Düzgün · sizin»;
 * - `other`: right, and another word the letters spell — the round's word,
 *   and theirs under it, green, «Sizin · bu söz də düzgündür»;
 * - `wrong`: the server said wrong — theirs under the word, red;
 * - null: no answer (or a host who does not play): the word alone.
 *
 * Right or wrong is `mine.correct` alone; the words are compared whole
 * only to know whether there is a second row to draw, never to judge.
 */
export function anagramMine(
    ask: RoomAsk,
    solution: string[],
    mine: RoomMine | null,
): 'same' | 'other' | 'wrong' | null {
    if (mine === null) {
        return null;
    }

    if (!mine.correct) {
        return 'wrong';
    }

    return lettersOf(ask, mine.picked).join('') ===
        lettersOf(ask, solution).join('')
        ? 'same'
        : 'other';
}
