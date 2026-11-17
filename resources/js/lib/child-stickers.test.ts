import { describe, expect, it } from 'vite-plus/test';
import {
    childStickers,
    newlyUnlockedStickers,
    STARS_PER_STICKER,
    STICKER_KEYS,
    unlockedStickerCount,
} from './child-stickers';

const AVATARS = STICKER_KEYS.map((key) => ({
    key,
    url: `/content/avatars/${key}.svg`,
}));

describe('childStickers', () => {
    it('unlocks one sticker per three stars up to twelve', () => {
        expect(STARS_PER_STICKER).toBe(3);
        expect(unlockedStickerCount(0)).toBe(0);
        expect(unlockedStickerCount(2)).toBe(0);
        expect(unlockedStickerCount(3)).toBe(1);
        expect(unlockedStickerCount(7)).toBe(2);
        expect(unlockedStickerCount(100)).toBe(STICKER_KEYS.length);

        const list = childStickers(7, AVATARS);

        expect(list).toHaveLength(12);
        expect(list.filter((s) => s.unlocked).map((s) => s.key)).toEqual([
            'panda',
            'rabbit',
        ]);
        expect(list[2]).toMatchObject({
            key: 'owl',
            starsRequired: 9,
            unlocked: false,
        });
    });

    it('reports how many new stickers a finished game unlocked', () => {
        expect(newlyUnlockedStickers(1, 1)).toBe(0);
        expect(newlyUnlockedStickers(1, 2)).toBe(1);
        expect(newlyUnlockedStickers(2, 4)).toBe(2);
        expect(newlyUnlockedStickers(36, 6)).toBe(0);
    });
});
