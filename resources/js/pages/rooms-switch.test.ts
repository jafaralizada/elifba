import { createElement } from 'react';
import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import az from '../../../lang/az.json';
import en from '../../../lang/en.json';
import Privacy from './privacy';
import Welcome from './welcome';
import type { WelcomeProps, WelcomeRooms } from '@/types/welcome';

/*
 * What the welcome page and the privacy notice draw with the rooms switch on
 * and off (frontend.md rule 10, architecture §12, ADR 019 decision 4). Every
 * page receives the whole dictionary, so "nothing of rooms" is read from
 * the markup a page draws from its props, never from a missing string.
 */

const shared: { props: Record<string, unknown>; component: string } = {
    props: {},
    component: 'welcome',
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => shared,
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
    }) =>
        createElement(
            'a',
            { href: typeof href === 'string' ? href : href.url, ...rest },
            children,
        ),
    router: { put: () => undefined },
}));

type Dictionary = Record<string, string>;

/** The page's text as React writes it into the markup. */
function escaped(text: string): string {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#x27;');
}

function share(
    translations: Dictionary,
    locale: string,
    area: string | null = null,
) {
    shared.props = {
        name: 'Elifba',
        locale,
        locales: ['az', 'en'],
        translations,
        auth: { user: area === null ? null : { id: 1 }, area, roles: [] },
    };
}

const rooms: WelcomeRooms = {
    families: [
        { key: 'memory', mechanics: ['recall_order', 'recall_cells'] },
        { key: 'logic', mechanics: ['next_in_sequence', 'odd_one_out'] },
        { key: 'words', mechanics: ['anagram', 'spelling'] },
    ],
    difficulties: [1, 2, 3, 4],
    maxPlayers: 20,
    rounds: { min: 5, max: 20 },
};

const welcome: WelcomeProps = {
    sourceUrl: 'https://example.org/elifba',
    mechanics: ['find'],
    packs: [],
    picturePacks: [],
    letters: [],
    avatars: [],
    sample: null,
};

const privacy = {
    retentionDays: 400,
    childrenMax: 10,
    googleSignIn: false,
    operator: { name: 'Məktəb', email: null },
};

/** Every string of a group, with the replacements the pages give it. */
function strings(translations: Dictionary, prefix: string): string[] {
    return Object.entries(translations)
        .filter(([key]) => key.startsWith(prefix))
        .map(([, text]) => text);
}

describe.each([
    ['az', az as Dictionary],
    ['en', en as Dictionary],
])('in %s', (locale, translations) => {
    beforeEach(() => share(translations, locale));

    it('draws nothing of rooms on the welcome page while the switch is off', () => {
        const html = renderToStaticMarkup(createElement(Welcome, welcome));

        expect(html).toContain('id="welcome-privacy"');
        expect(html).not.toContain('welcome-rooms');
        expect(html).not.toMatch(/href="\/(rooms|otaq)/);

        for (const text of [
            ...strings(translations, 'welcome.rooms.'),
            ...strings(translations, 'rooms.mechanics.'),
            ...strings(translations, 'rooms.setup.families.'),
        ]) {
            expect(html).not.toContain(escaped(text));
        }
    });

    it('draws the section for rooms on the welcome page from its prop', () => {
        const html = renderToStaticMarkup(
            createElement(Welcome, { ...welcome, rooms }),
        );
        const t = (key: string) => escaped(translations[key]);

        expect(html).toContain(t('welcome.rooms.title'));
        expect(html).toContain(
            escaped(
                translations['welcome.rooms.players'].replace(':count', '20'),
            ),
        );
        expect(html).toContain(
            escaped(
                translations['welcome.rooms.rounds']
                    .replace(':min', '5')
                    .replace(':max', '20'),
            ),
        );
        // Each difficulty's name stays on one line (no break inside «Çox çətin»).
        expect(html).toContain(
            escaped(
                translations['rooms.difficulty.4'].replaceAll(' ', '\u00a0'),
            ),
        );

        for (const family of rooms.families) {
            expect(html).toContain(t(`rooms.setup.families.${family.key}`));

            for (const mechanic of family.mechanics) {
                expect(html).toContain(t(`rooms.mechanics.${mechanic}.name`));
                expect(html).toContain(t(`rooms.mechanics.${mechanic}.line`));
            }
        }

        expect(html).toContain('href="/rooms"');
        expect(html).toContain('href="/oyun"');
        expect(html).toContain('href="/privacy#rooms"');
        // After the children's data and before the price (rule 10).
        expect(html.indexOf('id="welcome-privacy"')).toBeLessThan(
            html.indexOf('id="welcome-rooms"'),
        );
        expect(html.indexOf('id="welcome-rooms"')).toBeLessThan(
            html.indexOf('id="welcome-free"'),
        );
    });

    it('sends an account in the player area to its rooms', () => {
        share(translations, locale, 'player');
        const html = renderToStaticMarkup(
            createElement(Welcome, { ...welcome, rooms }),
        );

        expect(html).toContain(escaped(translations['welcome.rooms.hero']));
        expect(html).not.toContain(escaped(translations['welcome.hero.start']));
    });

    it('draws nothing of rooms on the privacy page while the switch is off', () => {
        const html = renderToStaticMarkup(createElement(Privacy, privacy));

        expect(html).toContain(escaped(translations['privacy.home.title']));
        expect(html).toContain(escaped(translations['privacy.parents.title']));
        expect(html).not.toContain('id="rooms"');

        for (const text of strings(translations, 'privacy.rooms.')) {
            // The longest piece of each part between its placeholders.
            const piece = text
                .split(/:[a-z_]+/)
                .reduce((a, b) => (b.length > a.length ? b : a));

            expect(html).not.toContain(escaped(piece));
        }
    });

    it('draws the section «Otaqlar» on the privacy page while the switch is on', () => {
        const html = renderToStaticMarkup(
            createElement(Privacy, { ...privacy, rooms: { idleHours: 3 } }),
        );
        const parts = Object.keys(translations).filter((key) =>
            key.startsWith('privacy.rooms.'),
        );

        expect(parts.length).toBeGreaterThan(5);

        for (const key of parts) {
            const text = translations[key]
                .replaceAll(':days', '400')
                .replaceAll(':hours', '3');

            for (const line of text.split('\n')) {
                expect(html).toContain(escaped(line));
            }
        }

        // The introduction names the third use, after the other two.
        expect(html).toContain(
            escaped(
                `${translations['privacy.intro']} ${translations['privacy.rooms.intro']}`,
            ),
        );
        // The teachers' and the parents' sections and the class note stay.
        expect(html).toContain(escaped(translations['privacy.home.title']));
        expect(html).toContain(escaped(translations['privacy.parents.title']));
        expect(html.match(/<strong>Məktəb<\/strong>/g)).toHaveLength(2);
    });
});
