import { describe, expect, it } from 'vitest';
import { spacedCode, typedAddress } from './room-code';

describe('typedAddress', () => {
    it('drops the scheme and a port the browser adds by itself', () => {
        expect(typedAddress('https://elifba.io:443/oyun')).toBe(
            'elifba.io/oyun',
        );
        expect(typedAddress('https://elifba.io/oyun')).toBe('elifba.io/oyun');
        expect(typedAddress('http://elifba.test:80/oyun')).toBe(
            'elifba.test/oyun',
        );
    });

    it('keeps a port a person has to type', () => {
        expect(typedAddress('http://192.168.1.20:8000/oyun')).toBe(
            '192.168.1.20:8000/oyun',
        );
        expect(typedAddress('https://elifba.example:8443/oyun')).toBe(
            'elifba.example:8443/oyun',
        );
    });
});

describe('spacedCode', () => {
    it('reads a code as two groups of three', () => {
        expect(spacedCode('482913')).toBe('482 913');
    });
});
