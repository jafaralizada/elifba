/**
 * How a room's link is sent (docs/frontend.md rule 15 "The invitation
 * link", ADR 033 decisions 2–3). «WhatsApp» is a link the person taps —
 * WhatsApp's own `wa.me` address with the whole message in its query — and
 * never a request of the page. The second button is the system's share
 * sheet on a touched device that has one, else copying the link, else
 * nothing; the choice is made from what the browser has, each time, and
 * never stored.
 */

/** WhatsApp's address that opens its chat picker with `text` ready to send. */
export function whatsAppHref(text: string): string {
    return `https://wa.me/?text=${encodeURIComponent(text)}`;
}

/** What the browser offers for sharing, as `shareWay()` reads it. */
export type ShareAbilities = {
    /** `navigator.share` exists. */
    share: boolean;
    /** `navigator.clipboard.writeText` exists. */
    clipboard: boolean;
    /** The page is a secure context (HTTPS, or localhost). */
    secure: boolean;
    /** The device is touched: `(pointer: coarse)`. */
    coarse: boolean;
};

/**
 * The second button of the share row: the system's sheet («Paylaş») on a
 * touched device that has one in a secure context, else copying («Linki
 * kopyala») where the clipboard is there, else none — over plain HTTP,
 * as `composer dev:lan` serves it, neither exists.
 */
export function shareWay({
    share,
    clipboard,
    secure,
    coarse,
}: ShareAbilities): 'sheet' | 'copy' | null {
    if (!secure) {
        return null;
    }

    if (share && coarse) {
        return 'sheet';
    }

    return clipboard ? 'copy' : null;
}

/** What this browser offers, read when the row is drawn. */
export function shareAbilities(): ShareAbilities {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') {
        return { share: false, clipboard: false, secure: false, coarse: false };
    }

    return {
        share: typeof navigator.share === 'function',
        clipboard: typeof navigator.clipboard?.writeText === 'function',
        secure: window.isSecureContext,
        coarse:
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(pointer: coarse)').matches,
    };
}
