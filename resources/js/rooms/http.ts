/**
 * A room page's own requests (docs/architecture.md §12 "The race"): the
 * state, the host's start and end of a game, a player's answer — JSON over
 * the account's session, with the XSRF cookie's token, never a bearer.
 * Each answer carries the local moments it was sent and received, so the
 * page can estimate the server's clock (`clock.ts`); the page never sends
 * its own clock.
 */
import { RoomState } from './contract';
import type { LocalClock } from './clock';

export function xsrfToken(): string | null {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : null;
}

export type Reply = { status: number; body: unknown };

/** A request that has not answered by then has failed, and is made again. */
export const TIMEOUT_MS = 10_000;

function headers(json: boolean): Record<string, string> {
    const xsrf = xsrfToken();

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(json ? { 'Content-Type': 'application/json' } : {}),
        ...(xsrf === null ? {} : { 'X-XSRF-TOKEN': xsrf }),
    };
}

/** A write — the host's start or end of a game, an answer; rejects when nothing came back in time. */
export async function roomRequest(
    method: 'POST' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<Reply> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        signal: AbortSignal.timeout(TIMEOUT_MS),
        headers: headers(body !== undefined),
        body: body === undefined ? null : JSON.stringify(body),
    });
    const text = await response.text();
    let parsed: unknown = null;

    try {
        parsed = text === '' ? null : JSON.parse(text);
    } catch {
        parsed = null;
    }

    return { status: response.status, body: parsed };
}

export type FetchedState =
    | { kind: 'state'; state: RoomState; sentAt: number; receivedAt: number }
    /** Removed, gone, the room closed under the page, the session over: the page reloads to say which. */
    | { kind: 'refused' }
    | { kind: 'failed' };

/** `GET /rooms/{room}/state`, timed by the local clock. */
export async function fetchState(
    url: string,
    local: LocalClock = () => performance.now(),
): Promise<FetchedState> {
    const sentAt = local();

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: AbortSignal.timeout(TIMEOUT_MS),
            headers: headers(false),
        });
        // The server read its clock before it answered: the headers are the bound.
        const receivedAt = local();

        if ([401, 403, 404, 419].includes(response.status)) {
            return { kind: 'refused' };
        }

        if (!response.ok) {
            return { kind: 'failed' };
        }

        const state = RoomState.safeParse(await response.json());

        return state.success
            ? { kind: 'state', state: state.data, sentAt, receivedAt }
            : { kind: 'failed' };
    } catch {
        return { kind: 'failed' };
    }
}
