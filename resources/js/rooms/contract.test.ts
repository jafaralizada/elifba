import { describe, expect, it } from 'vite-plus/test';
import type { z } from 'zod';
import { RoomChangedEvent, RoomState } from './contract';

/*
 * Every fixture in tests/Fixtures/rooms parses with its strict schema; Pest
 * builds the same lobby from rows and round-trips the file through the PHP
 * DTOs (tests/Feature/Rooms/RoomStateTest.php). The prefix of a file name
 * names its schema. B3 adds the states of a game.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/*.json',
    { eager: true, import: 'default' },
);

const schemas: Record<string, z.ZodType> = {
    state: RoomState,
};

function schemaOf(path: string): z.ZodType | undefined {
    const name = path.split('/').pop() ?? '';

    return schemas[name.slice(0, name.indexOf('.'))];
}

const lobby = fixtures['../../../tests/Fixtures/rooms/state.lobby.json'];

// judge.json holds the cases of the server's judge (RoomJudgeTest), not a
// payload a device receives.
const payloads = Object.entries(fixtures).filter(
    ([path]) => !path.endsWith('/judge.json'),
);

describe('room fixtures', () => {
    it('accepts the lobby fixture', () => {
        const result = RoomState.safeParse(lobby);

        expect(result.error).toBeUndefined();
        expect(result.data).toEqual(lobby);
    });

    it.each(payloads)('%s parses strictly', (path, fixture) => {
        const result = schemaOf(path)?.safeParse(fixture);

        expect(result?.error).toBeUndefined();
        expect(result?.data).toEqual(fixture);
    });
});

describe('what a state never carries', () => {
    const state = lobby as z.infer<typeof RoomState>;

    it('refuses a state that carries an account id or an address', () => {
        const [first, ...rest] = state.players;

        for (const extra of [
            { user_id: 7 },
            { account_id: 7 },
            { email: 'resad@example.com' },
            { ip: '10.0.0.7' },
        ]) {
            expect(
                RoomState.safeParse({
                    ...state,
                    players: [{ ...first, ...extra }, ...rest],
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({ ...state, me: { ...state.me, ...extra } })
                    .success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...state,
                    room: { ...state.room, ...extra },
                }).success,
            ).toBe(false);
            expect(RoomState.safeParse({ ...state, ...extra }).success).toBe(
                false,
            );
        }
    });

    it('refuses a player known by anything but a row id', () => {
        const [first, ...rest] = state.players;

        expect(
            RoomState.safeParse({
                ...state,
                players: [{ ...first, id: 7 }, ...rest],
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({ ...state, me: { ...state.me, player_id: 7 } })
                .success,
        ).toBe(false);
    });

    it('refuses a game, a round or a ranking before B3 gives them a shape', () => {
        expect(
            RoomState.safeParse({ ...state, game: { id: state.room.id } })
                .success,
        ).toBe(false);
        expect(
            RoomState.safeParse({ ...state, round: { id: state.room.id } })
                .success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...state,
                ranking: [{ player_id: state.players[0].id, points: 20 }],
            }).success,
        ).toBe(false);
    });
});

describe('room.changed', () => {
    it('carries the revision and nothing else', () => {
        expect(RoomChangedEvent.safeParse({ v: 1, revision: 4 }).success).toBe(
            true,
        );
        expect(
            RoomChangedEvent.safeParse({ v: 1, revision: 4, name: 'Aygün' })
                .success,
        ).toBe(false);
    });
});
