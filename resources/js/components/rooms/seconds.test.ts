import { describe, expect, it } from 'vite-plus/test';
import { seconds } from './seconds';

describe('seconds', () => {
    it('writes tenths with the language’s decimal mark', () => {
        expect(seconds(2449, ',', true)).toBe('2,4');
        expect(seconds(2450, '.', true)).toBe('2.5');
        expect(seconds(3000, ',', true)).toBe('3,0');
        expect(seconds(950, ',', true)).toBe('1,0');
    });

    it('drops the tenth of a whole number unless asked to keep it', () => {
        expect(seconds(10_000, ',')).toBe('10');
        expect(seconds(2500, ',')).toBe('2,5');
        expect(seconds(0, ',')).toBe('0');
    });
});
