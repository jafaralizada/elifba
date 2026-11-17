import type { RoomRankedPlayer } from '@/rooms/contract';

/** The rows of one place in a ranking: its label once, then the players who share it. */
export type StandingGroup = {
    /** The place, or null for the players without points, listed last. */
    place: number | null;
    /** The place's label from the page's `place_labels`; null without a place. */
    label: string | null;
    rows: RoomRankedPlayer[];
};

/**
 * A ranking as the room pages show it (docs/frontend.md rule 15, "The
 * ranking"): the rows in the server's order, gathered by place, so a
 * place that players share is shown once, beside all of them. The label
 * of place n is `placeLabels[n − 1]`, from the server (`Ordinal::label()`):
 * nothing here suffixes a number. A place is only what the server says;
 * nothing is ranked here.
 */
export function standingGroups(
    ranking: RoomRankedPlayer[],
    placeLabels: string[],
): StandingGroup[] {
    const groups: StandingGroup[] = [];

    for (const row of ranking) {
        const last = groups.at(-1);

        if (last !== undefined && last.place === row.place) {
            last.rows.push(row);

            continue;
        }

        groups.push({
            place: row.place,
            label:
                row.place === null
                    ? null
                    : (placeLabels[row.place - 1] ?? null),
            rows: [row],
        });
    }

    return groups;
}
