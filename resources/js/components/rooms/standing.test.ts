import { describe, expect, it } from 'vite-plus/test';
import { standingGroups } from './standing-groups';

/*
 * A room's ranking on its pages (docs/frontend.md rule 15, architecture §12
 * "Scoring and what is kept"): dense places from the server, each label
 * from `place_labels`, a shared place shown once, players without points
 * last without one.
 */
const LABELS = ['1-ci', '2-ci', '3-cü', '4-cü', '5-ci'];

describe('standingGroups', () => {
    it('shows a shared place once', () => {
        const groups = standingGroups(
            [
                { player_id: 'aysel', points: 37, place: 1 },
                { player_id: 'murad', points: 37, place: 1 },
                { player_id: 'nergiz', points: 20, place: 2 },
                { player_id: 'tural', points: 12, place: 3 },
                { player_id: 'leyla', points: 12, place: 3 },
                { player_id: 'samir', points: 12, place: 3 },
                { player_id: 'vusal', points: 0, place: null },
                { player_id: 'zaur', points: 0, place: null },
            ],
            LABELS,
        );

        expect(groups.map((group) => group.label)).toEqual([
            '1-ci',
            '2-ci',
            '3-cü',
            null,
        ]);
        expect(
            groups.map((group) => group.rows.map((row) => row.player_id)),
        ).toEqual([
            ['aysel', 'murad'],
            ['nergiz'],
            ['tural', 'leyla', 'samir'],
            ['vusal', 'zaur'],
        ]);
        // Every row once, in the server's order.
        expect(groups.flatMap((group) => group.rows).length).toBe(8);
    });

    it('labels each place from the props and never makes a label', () => {
        const groups = standingGroups(
            [
                { player_id: 'a', points: 20, place: 1 },
                { player_id: 'b', points: 10, place: 2 },
            ],
            ['first', 'second'],
        );

        expect(groups.map((group) => group.label)).toEqual(['first', 'second']);
        expect(
            standingGroups([{ player_id: 'a', points: 5, place: 9 }], LABELS)[0]
                .label,
        ).toBeNull();
    });

    it('lists nothing for an empty ranking', () => {
        expect(standingGroups([], LABELS)).toEqual([]);
    });
});
