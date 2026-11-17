import { describe, expect, it } from 'vite-plus/test';
import { pickRandomStudent } from './random-student';

describe('pickRandomStudent', () => {
    it('returns null for an empty class and the lone student for a class of one', () => {
        expect(pickRandomStudent([], null)).toBeNull();
        expect(pickRandomStudent(['s1'], 's1')).toBe('s1');
    });

    it('prefers students who have not played yet and never repeats the current student', () => {
        const ids = ['s1', 's2', 's3', 's4'];
        const played = new Set(['s1', 's2']);

        expect(pickRandomStudent(ids, 's2', played, () => 0)).toBe('s3');
        expect(pickRandomStudent(ids, 's2', played, () => 0.99)).toBe('s4');
    });

    it('falls back to everyone but the current student once all have played', () => {
        const ids = ['s1', 's2', 's3'];
        const played = new Set(['s1', 's2', 's3']);

        expect(pickRandomStudent(ids, 's2', played, () => 0)).toBe('s1');
        expect(pickRandomStudent(ids, 's2', played, () => 0.99)).toBe('s3');
    });
});
