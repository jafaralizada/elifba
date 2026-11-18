import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomState as State } from '@/rooms/contract';
import {
    countUpValue,
    rankShift,
    roomMoment,
    sawBegin,
    stagger,
    stripMoves,
} from './motion';

/*
 * A room game's motion (docs/frontend.md rule 15 "Motion", ADR 025): a page
 * animates only a moment it saw begin, so a reload or a state fetched again
 * replays nothing; a ranking's rows move only between two orders the
 * server gave; a count shows the server's numbers at both ends.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): State {
    return RoomState.parse(
        fixtures[`../../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

/** The same state with its round in another phase or index, or its game over. */
function moved(
    base: State,
    change: { index?: number; phase?: 'study' | 'answer' | 'reveal' },
): State {
    if (base.round === null) {
        throw new Error('a round is needed');
    }

    return {
        ...base,
        round: {
            ...base.round,
            index: change.index ?? base.round.index,
            phase: change.phase ?? base.round.phase,
        },
    } as State;
}

describe('roomMoment', () => {
    it("names the lobby, a round in its phase and a game's end", () => {
        expect(roomMoment(null).phase).toBe('none');
        expect(roomMoment(state('lobby'))).toEqual({
            game: null,
            round: null,
            phase: 'lobby',
        });

        const countdown = state('countdown');
        expect(roomMoment(countdown)).toEqual({
            game: countdown.game?.id,
            round: 0,
            phase: 'countdown',
        });

        const over = state('over');
        expect(roomMoment(over)).toEqual({
            game: over.game?.id,
            round: null,
            phase: 'over',
        });
    });
});

describe('sawBegin', () => {
    it('draws the moment a page opened on still', () => {
        const reveal = state('reveal');
        const first = roomMoment(reveal);

        // The same moment fetched again (room.changed, the poll): nothing replays.
        expect(
            sawBegin(first, roomMoment(structuredClone(reveal)), 'phase'),
        ).toBe(false);
        expect(sawBegin(first, roomMoment(reveal), 'round')).toBe(false);

        // The next round's question: the page saw it begin.
        const next = moved(reveal, {
            index: (reveal.round?.index ?? 0) + 1,
            phase: 'answer',
        });
        expect(sawBegin(first, roomMoment(next), 'phase')).toBe(true);
        expect(sawBegin(first, roomMoment(next), 'round')).toBe(true);
    });

    it('keeps a round one moment at round depth through its phases', () => {
        const answer = state('answer');
        const first = roomMoment(answer);
        const reveal = moved(answer, { phase: 'reveal' });

        expect(sawBegin(first, roomMoment(reveal), 'phase')).toBe(true);
        expect(sawBegin(first, roomMoment(reveal), 'round')).toBe(false);
    });

    it("plays a game's end only when the page saw the game run", () => {
        const over = state('over');

        // Opened, or reloaded, on the final: still.
        expect(sawBegin(roomMoment(over), roomMoment(over), 'phase')).toBe(
            false,
        );
        // Watched the last reveal, then the end: it plays.
        expect(
            sawBegin(roomMoment(state('reveal')), roomMoment(over), 'phase'),
        ).toBe(true);
        // Opened in the lobby, a game ran and ended: it plays.
        expect(
            sawBegin(roomMoment(state('lobby')), roomMoment(over), 'phase'),
        ).toBe(true);
    });
});

describe('stripMoves', () => {
    it('draws the strip still during a question and on a reveal the page opened on', () => {
        const countdown = state('countdown');
        const answer = state('answer');
        const reveal = moved(answer, { phase: 'reveal' });
        const opened = roomMoment(countdown);

        // The countdown, the study and the question: still, whatever the page saw begin.
        expect(stripMoves(opened, roomMoment(countdown))).toBe(false);
        expect(stripMoves(opened, roomMoment(state('study')))).toBe(false);
        expect(stripMoves(opened, roomMoment(answer))).toBe(false);
        // A reveal the page saw begin: it moves, once.
        expect(stripMoves(opened, roomMoment(reveal))).toBe(true);
        expect(stripMoves(roomMoment(answer), roomMoment(reveal))).toBe(true);
        // Opened, reloaded or joined on the reveal: complete and still.
        expect(stripMoves(roomMoment(reveal), roomMoment(reveal))).toBe(false);
        expect(
            stripMoves(roomMoment(reveal), roomMoment(structuredClone(reveal))),
        ).toBe(false);
    });
});

describe('rankShift', () => {
    it('moves rows only between two orders the server gave', () => {
        const before = [
            { player_id: 'aysel', points: 20, place: 1 },
            { player_id: 'murad', points: 17, place: 2 },
            { player_id: 'nergiz', points: 0, place: null },
        ];
        const after = [
            { player_id: 'murad', points: 36, place: 1 },
            { player_id: 'aysel', points: 20, place: 2 },
            { player_id: 'nergiz', points: 14, place: 3 },
            { player_id: 'tural', points: 0, place: null },
        ];

        expect(Object.fromEntries(rankShift(before, after))).toEqual({
            murad: 1,
            aysel: -1,
            nergiz: 0,
        });
    });

    it('moves nothing without a previous order the page showed', () => {
        expect(
            rankShift(null, [{ player_id: 'aysel', points: 20, place: 1 }])
                .size,
        ).toBe(0);
    });
});

describe('countUpValue', () => {
    it("counts the server's numbers, from one to the other and never past it", () => {
        expect(countUpValue(20, 37, 0)).toBe(20);
        expect(countUpValue(20, 37, 1)).toBe(37);
        expect(countUpValue(20, 37, 1.4)).toBe(37);

        let last = 20;

        for (let step = 0; step <= 20; step++) {
            const value = countUpValue(20, 37, step / 20);

            expect(Number.isInteger(value)).toBe(true);
            expect(value).toBeGreaterThanOrEqual(last);
            expect(value).toBeLessThanOrEqual(37);
            last = value;
        }

        expect(countUpValue(0, 0, 0.5)).toBe(0);
    });
});

describe('stagger', () => {
    it('brings the last item in within the cap', () => {
        expect(stagger(0)).toBe(0);
        expect(stagger(2)).toBe(50);
        expect(stagger(30)).toBe(150);
        expect(stagger(30, 50, 400)).toBe(400);
    });
});
