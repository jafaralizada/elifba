import { describe, expect, it } from 'vitest';
import { scannedCode, scanOrigins } from './scan';

/*
 * The camera's one rule (architecture §12 "Games first", "The camera";
 * ADR 026 decision 15): only this app's own join address gives a code.
 */
const origins = ['https://elifba.io'];

describe('scannedCode', () => {
    it("takes the code from this app's own join address", () => {
        expect(scannedCode('https://elifba.io/oyun/482913', origins)).toBe(
            '482913',
        );
        expect(scannedCode('  https://elifba.io/oyun/482913\n', origins)).toBe(
            '482913',
        );
        expect(
            scannedCode('https://ELIFBA.io/oyun/004211?from=qr', origins),
        ).toBe('004211');
        expect(
            scannedCode(
                'http://192.168.1.20:8000/oyun/482913',
                scanOrigins(
                    'https://elifba.io',
                    'http://192.168.1.20:8000/oyun',
                ),
            ),
        ).toBe('482913');
    });

    it("takes the code from this app's join address with its tag", () => {
        // From I2 the QR and the shared link carry the room's tag (ADR 033 decision 5).
        expect(
            scannedCode('https://elifba.io/oyun/482913?t=8b379c', origins),
        ).toBe('482913');
    });

    it('takes the old /otaq address too', () => {
        expect(scannedCode('https://elifba.io/otaq/482913', origins)).toBe(
            '482913',
        );
    });

    it("refuses another site's address, another path and a bare number", () => {
        for (const text of [
            'https://elifba.example/oyun/482913',
            'https://elifba.io.evil.example/oyun/482913',
            'http://elifba.io/oyun/482913',
            'https://user@elifba.io/oyun/482913',
            'https://elifba.io/pult/482913',
            'https://elifba.io/oyun/48291',
            'https://elifba.io/oyun/4829131',
            'https://elifba.io/oyun/482913/extra',
            'https://elifba.io/oyun',
            'https://elifba.io/rooms/01jd5k2m3n4p5q6r7s8t9v0w1x',
            'javascript:alert(1)',
            '482913',
            '482 913',
            '',
        ]) {
            expect(scannedCode(text, origins), text).toBeNull();
        }
    });
});

describe('scanOrigins', () => {
    it("is the page's own origin and the entrance's", () => {
        expect(
            scanOrigins('https://elifba.io', 'https://elifba.io/oyun'),
        ).toEqual(['https://elifba.io']);
        expect(
            scanOrigins('https://elifba.io', 'http://10.0.0.5:8000/oyun'),
        ).toEqual(['https://elifba.io', 'http://10.0.0.5:8000']);
        expect(scanOrigins('https://elifba.io', null)).toEqual([
            'https://elifba.io',
        ]);
        expect(scanOrigins('https://elifba.io', 'not an address')).toEqual([
            'https://elifba.io',
        ]);
    });
});
