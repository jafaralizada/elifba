import type { RoomItem, RoomMine } from '@/rooms/contract';

/*
 * How a `choice` round is laid out and marked (docs/frontend.md rule 15,
 * architecture §12 "Room games"), as plain functions: the page draws the
 * options in the server's order and learns which one was right only from
 * `reveal.solution`, and whether the viewer's was right only from
 * `reveal.mine.correct`. Nothing here works out a term, a property or a
 * verdict.
 */

/**
 * The columns of the options: two by two when they pair up, one column
 * when they do not — so every option is the same size and none stands
 * apart from the others, which in «Artıq olanı tap» would read as a hint.
 */
export function optionColumns(count: number): 1 | 2 {
    return count % 2 === 0 ? 2 : 1;
}

/** The longest text among the items, in UTF-16 units — one per letter of Azerbaijani (precomposed, in the BMP); a «?» counts as one. */
export function longestText(items: RoomItem[]): number {
    return items.reduce(
        (longest, item) => Math.max(longest, (item.text ?? '').length),
        1,
    );
}

/**
 * The font size of a row of `cells` equal cells across its container
 * (`@container`, `cqw`), so a row of six three-digit terms and its «?»
 * stays on one line at 375px: each cell fits `longest` tabular figures
 * (about 0.62em each) and a little room, never above `max`.
 */
export function rowFont(
    cells: number,
    longest: number,
    gap: string,
    max: string,
): string {
    const ems = Math.round(cells * (longest * 0.62 + 0.6) * 100) / 100;

    return `min(${max}, calc((100cqw - ${cells - 1} * ${gap}) / ${ems}))`;
}

/** What the reveal says of an option: the right one, the viewer's own, both or neither. */
export type OptionMark = {
    right: boolean;
    yours: boolean;
    /** Its colour: right, wrong (the viewer's, when the server said wrong) or none. */
    tone: 'right' | 'wrong' | null;
};

/**
 * Each option's mark in the reveal: the solution's id is the right one;
 * the viewer's own is the id they picked, coloured by the server's
 * verdict (`mine.correct`), never by comparing it here.
 */
export function optionMark(
    id: string,
    solution: string[],
    mine: RoomMine | null,
): OptionMark {
    const right = solution.includes(id);
    const yours = mine?.picked.includes(id) ?? false;

    return {
        right,
        yours,
        tone: right
            ? 'right'
            : yours
              ? mine?.correct === true
                  ? 'right'
                  : 'wrong'
              : null,
    };
}
