import { describe, expect, it } from 'vitest';
import { shareWay, whatsAppHref } from './share';

/*
 * The share row's two rules (docs/frontend.md rule 15 "The invitation
 * link", ADR 033 decisions 2–3): WhatsApp gets the message in its own
 * address and nothing else, and the second button is what the browser has.
 */
describe('whatsAppHref', () => {
    it("puts the message and the link alone in WhatsApp's address", () => {
        const text =
            'Gəl, birlikdə oynayaq! Oyuna qoşul: https://elifba.io/oyun/482913?t=8b379c';
        const href = whatsAppHref(text);
        const url = new URL(href);

        expect(href.startsWith('https://wa.me/?text=')).toBe(true);
        expect(url.origin).toBe('https://wa.me');
        expect(url.pathname).toBe('/');
        expect([...url.searchParams.keys()]).toEqual(['text']);
        expect(url.searchParams.get('text')).toBe(text);
        // Encoded whole: the link's own `?` and `&` stay inside the text.
        expect(href).not.toContain('?t=');
        expect(href).toContain(encodeURIComponent('?t=8b379c'));
    });
});

describe('shareWay', () => {
    const all = { share: true, clipboard: true, secure: true, coarse: true };

    it("offers the system's sheet only on a touched device that has one", () => {
        expect(shareWay(all)).toBe('sheet');
        expect(shareWay({ ...all, coarse: false })).toBe('copy');
        expect(shareWay({ ...all, share: false })).toBe('copy');
    });

    it('copies where there is no sheet, and offers neither on an insecure page', () => {
        expect(
            shareWay({
                share: false,
                clipboard: true,
                secure: true,
                coarse: false,
            }),
        ).toBe('copy');
        expect(
            shareWay({
                share: false,
                clipboard: false,
                secure: true,
                coarse: true,
            }),
        ).toBeNull();
        expect(shareWay({ ...all, secure: false })).toBeNull();
        expect(
            shareWay({
                share: false,
                clipboard: false,
                secure: false,
                coarse: false,
            }),
        ).toBeNull();
    });
});
