/**
 * What the code field's camera accepts (docs/architecture.md §12 "Games
 * first", "The camera"; ADR 026 decision 15). A scanned QR is read on the
 * device and only as text: it counts when it is an address of this app —
 * the page's own origin or the entrance's (`APP_URL`, which `composer
 * dev:lan` or a proxy may make another) — whose path is `/oyun/{six
 * digits}`, or `/otaq/{six digits}` from before G2. The page never opens
 * what it read: it takes the six digits and visits `rooms.join` with them,
 * so a scanned code meets the route, the limiter and the refusals a typed
 * one meets. Anything else is not a code of an Elifba game.
 */

/** The six digits of a scanned text, when it is this app's own join address; null otherwise. */
export function scannedCode(
    text: string,
    origins: readonly string[],
): string | null {
    let url: URL;

    try {
        url = new URL(text.trim());
    } catch {
        return null;
    }

    if (
        (url.protocol !== 'https:' && url.protocol !== 'http:') ||
        url.username !== '' ||
        url.password !== '' ||
        !origins.includes(url.origin)
    ) {
        return null;
    }

    const match = /^\/(?:oyun|otaq)\/(\d{6})$/.exec(url.pathname);

    return match === null ? null : match[1];
}

/** The origins a scanned address may come from: the page's own, and the entrance's when it has one. */
export function scanOrigins(
    pageOrigin: string,
    entrance: string | null,
): string[] {
    const origins = [pageOrigin];

    if (entrance !== null) {
        try {
            const origin = new URL(entrance).origin;

            if (!origins.includes(origin)) {
                origins.push(origin);
            }
        } catch {
            // An entrance that is no address adds nothing.
        }
    }

    return origins;
}
