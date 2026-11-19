import { describe, expect, it } from 'vitest';
import { pageShell, shellDecided } from './page-shell';

// Every page component, by the name Inertia gives it (`rooms/show`).
const pages = Object.keys(import.meta.glob('../pages/**/*.tsx')).map((path) =>
    path.replace(/^\.\.\/pages\//, '').replace(/\.tsx$/, ''),
);

describe('pageShell', () => {
    it("draws a room's page in its own game frame, never inside the app's shell (ADR 032)", () => {
        expect(pageShell('rooms/show')).toBe('none');
    });

    it("draws a room's screen without the shell", () => {
        expect(pageShell('rooms/screen')).toBe('none');
    });

    it("keeps «Oyunlar», «Keçmiş oyunlar» and the entrance inside the area's shell", () => {
        expect(pageShell('rooms/index')).toBe('app');
        expect(pageShell('rooms/past')).toBe('app');
        expect(pageShell('rooms/join')).toBe('app');
    });

    it("draws the invitation's door in the auth card (ADR 033)", () => {
        expect(pageShell('rooms/invited')).toBe('auth');
        expect(shellDecided('rooms/invited')).toBe(true);
    });

    it('decides the shell of every room page by name', () => {
        const rooms = pages.filter((page) => page.startsWith('rooms/'));

        expect(rooms).toContain('rooms/show');
        expect(rooms).toContain('rooms/invited');
        expect(rooms.filter((page) => !shellDecided(page))).toEqual([]);
    });

    it('keeps the board, the remote and the pairing pages without the shell', () => {
        expect(pageShell('games/board')).toBe('none');
        expect(pageShell('games/remote')).toBe('none');
        expect(pageShell('lovhe')).toBe('none');
        expect(pageShell('pult/pair')).toBe('none');
    });

    it('keeps the public pages without the shell', () => {
        expect(pageShell('welcome')).toBe('none');
        expect(pageShell('privacy')).toBe('none');
        expect(pageShell('credits')).toBe('none');
    });

    it("keeps a teacher's and a parent's pages, settings and auth in their shells", () => {
        expect(pageShell('classes/index')).toBe('app');
        expect(pageShell('children/show')).toBe('app');
        expect(pageShell('games/setup')).toBe('app');
        expect(pageShell('start')).toBe('app');
        expect(pageShell('settings/profile')).toBe('settings');
        expect(pageShell('auth/login')).toBe('auth');
    });

    it('names a shell for every page there is', () => {
        expect(pages.length).toBeGreaterThan(20);

        for (const page of pages) {
            expect(['none', 'auth', 'app', 'settings']).toContain(
                pageShell(page),
            );
        }
    });
});
