import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { ROOM_STATE_VERSION } from './contract';
import { fetchState } from './http';

/*
 * `fetchState` (docs/architecture.md §12 "Games first", ADR 026
 * "Consequences"): a state this page reads is a state; one of another
 * version — a deploy happened under the page — is refused, so the page
 * reloads and takes the new pages instead of asking again and again.
 */

const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/state.lobby.json',
    { eager: true, import: 'default' },
);
const lobby = Object.values(fixtures)[0] as Record<string, unknown>;

function answering(body: unknown, status = 200): void {
    vi.stubGlobal('document', { cookie: '' });
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify(body), { status })),
    );
}

describe('fetchState', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('reads a state of its own version', async () => {
        answering(lobby);

        const fetched = await fetchState('/rooms/x/state', () => 0);

        expect(lobby.v).toBe(ROOM_STATE_VERSION);
        expect(fetched.kind).toBe('state');
    });

    it('is refused a state of another version, so the page reloads', async () => {
        for (const v of [ROOM_STATE_VERSION - 1, ROOM_STATE_VERSION + 1]) {
            answering({ ...lobby, v });

            expect((await fetchState('/rooms/x/state', () => 0)).kind).toBe(
                'refused',
            );
        }
    });

    it('asks again after a body it cannot read', async () => {
        answering({ ...lobby, setup: { mechanic: 'find' } });

        expect((await fetchState('/rooms/x/state', () => 0)).kind).toBe(
            'failed',
        );
    });
});
