import type { Avatar } from '@/types';

/** Stars needed per sticker in a child's sticker album. */
export const STARS_PER_STICKER = 3;

/**
 * The twelve animal stickers of a child's album (`content/avatars.json`
 * keys, already loaded on the child's page).
 */
export const STICKER_KEYS = [
    'panda',
    'rabbit',
    'owl',
    'bear',
    'elephant',
    'giraffe',
    'monkey',
    'penguin',
    'parrot',
    'frog',
    'sloth',
    'narwhal',
] as const;

export type StickerKey = (typeof STICKER_KEYS)[number];

export type ChildSticker = {
    key: StickerKey;
    url: string;
    starsRequired: number;
    unlocked: boolean;
};

/** How many stickers `stars` unlocks in total (at most `STICKER_KEYS.length`). */
export function unlockedStickerCount(stars: number): number {
    return Math.min(
        STICKER_KEYS.length,
        Math.max(0, Math.floor(stars / STARS_PER_STICKER)),
    );
}

/** How many new stickers were unlocked by earning `earnedStars` on top of `beforeStars`. */
export function newlyUnlockedStickers(
    beforeStars: number,
    earnedStars: number,
): number {
    if (earnedStars <= 0) {
        return 0;
    }

    return Math.max(
        0,
        unlockedStickerCount(beforeStars + earnedStars) -
            unlockedStickerCount(beforeStars),
    );
}

/**
 * The child's sticker album, derived from their own total stars and the
 * avatar pictures on the page — never compared with a sibling's (ADR 011).
 */
export function childStickers(
    totalStars: number,
    avatars: readonly Avatar[],
): ChildSticker[] {
    const byKey = new Map(avatars.map((avatar) => [avatar.key, avatar.url]));

    return STICKER_KEYS.flatMap((key, index): ChildSticker[] => {
        const url = byKey.get(key);

        if (url === undefined) {
            return [];
        }

        const starsRequired = (index + 1) * STARS_PER_STICKER;

        return [
            {
                key,
                url,
                starsRequired,
                unlocked: totalStars >= starsRequired,
            },
        ];
    });
}
