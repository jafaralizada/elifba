/**
 * Which shell a page is drawn in, by the page's name (docs/frontend.md rule
 * 1): none, the auth card, the app's shell (`AppLayout`: the header, the
 * sidebar from `lg`, the tab bar below it) or the app's shell with the
 * settings' own. `app.tsx` maps it to the layouts; this module stays pure so
 * a test can hold the choice.
 */
export type PageShell = 'none' | 'auth' | 'app' | 'settings';

/**
 * The pages that fill their screen and draw their own frame: the public
 * pages, the board (`games/board`, `lovhe`) and the remote, a room's screen
 * (B4) and, from F2, a room's page (ADR 032: `rooms/show` in `RoomFrame`).
 * The phone's pairing pages (`pult/*`) are matched by their prefix.
 */
const WITHOUT_SHELL = new Set([
    'welcome',
    'privacy',
    'credits',
    'games/board',
    'games/remote',
    'lovhe',
    'rooms/screen',
    'rooms/show',
]);

/**
 * The room pages inside the area's shell (ADR 032 decision 2): «Oyunlar»,
 * «Keçmiş oyunlar» and the entrance. A room page that is a part of a game
 * is in `WITHOUT_SHELL` instead; every room page is in one of the two.
 */
export const ROOM_PAGES_IN_SHELL = ['rooms/index', 'rooms/past', 'rooms/join'];

export function pageShell(name: string): PageShell {
    if (WITHOUT_SHELL.has(name) || name.startsWith('pult/')) {
        return 'none';
    }

    if (name.startsWith('auth/')) {
        return 'auth';
    }

    if (name.startsWith('settings/')) {
        return 'settings';
    }

    return 'app';
}

/** Whether the page's shell was decided by name rather than by default: every room page must be. */
export function shellDecided(name: string): boolean {
    return WITHOUT_SHELL.has(name) || ROOM_PAGES_IN_SHELL.includes(name);
}
