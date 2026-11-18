import { usePage } from '@inertiajs/react';
import {
    BookOpenText,
    Gamepad2,
    History,
    House,
    Palette,
    School,
    Settings,
    ShieldCheck,
    UserCog,
    UserRound,
} from 'lucide-react';
import type { NavArea, NavPage } from '@/lib/nav-active';
import { edit as editAppearance } from '@/routes/appearance';
import { index as children } from '@/routes/children';
import { index as classes } from '@/routes/classes';
import { edit as editPlaySettings } from '@/routes/play-settings';
import { edit as editProfile } from '@/routes/profile';
import { edit as editRoles } from '@/routes/roles';
import { past as pastRooms, index as rooms } from '@/routes/rooms';
import { edit as editSecurity } from '@/routes/security';
import { index as words } from '@/routes/words';
import type { Role } from '@/types';

const classesArea: NavArea = {
    key: 'classes',
    title: 'nav.classes',
    icon: School,
    base: '/classes',
    pages: [{ title: 'nav.classes', href: classes(), icon: School }],
};

const wordsArea: NavArea = {
    key: 'words',
    title: 'nav.words',
    icon: BookOpenText,
    base: '/words',
    pages: [{ title: 'nav.words', href: words(), icon: BookOpenText }],
};

const childrenArea: NavArea = {
    key: 'children',
    title: 'nav.children',
    icon: House,
    base: '/children',
    pages: [{ title: 'nav.children', href: children(), icon: House }],
};

/** «Oyunlar» (ADR 026 decision 13): the games, a room's pages and its screen. */
const roomsArea: NavArea = {
    key: 'rooms',
    title: 'nav.rooms',
    icon: Gamepad2,
    base: '/rooms',
    pages: [{ title: 'nav.rooms', href: rooms(), icon: Gamepad2 }],
};

/** «Keçmiş oyunlar» (ADR 026 decision 14): under `/rooms`, but its own entry. */
const pastRoomsArea: NavArea = {
    key: 'rooms-past',
    title: 'nav.rooms_past',
    icon: History,
    base: '/rooms/past',
    pages: [{ title: 'nav.rooms_past', href: pastRooms(), icon: History }],
};

const profilePage: NavPage = {
    title: 'settings.nav.profile',
    href: editProfile(),
    icon: UserRound,
};
const securityPage: NavPage = {
    title: 'settings.nav.security',
    href: editSecurity(),
    icon: ShieldCheck,
};
const appearancePage: NavPage = {
    title: 'settings.nav.appearance',
    href: editAppearance(),
    icon: Palette,
};
const playPage: NavPage = {
    title: 'settings.nav.play',
    href: editPlaySettings(),
    icon: Gamepad2,
};
const rolesPage: NavPage = {
    title: 'settings.nav.roles',
    href: editRoles(),
    icon: UserCog,
};

const settingsArea: NavArea = {
    key: 'settings',
    title: 'nav.settings',
    icon: Settings,
    base: '/settings',
    pages: [profilePage, securityPage, appearancePage, playPage, rolesPage],
};

/**
 * The player's Settings: the same pages but «Oyun», which is the boards'
 * and opens only for a teacher or a parent (ADR 019 decision 8).
 */
const playerSettingsArea: NavArea = {
    ...settingsArea,
    pages: [profilePage, securityPage, appearancePage, rolesPage],
};

/**
 * The shell's one nav definition (docs/frontend.md §1), one navigation per
 * area (architecture §1 "Accounts and roles", §12 for the player): the
 * sidebar, the bottom bar and the pill strip read the one of the page's
 * area. Titles are translation keys.
 */
export const navAreas: Record<Role, NavArea[]> = {
    teacher: [classesArea, wordsArea, settingsArea],
    parent: [childrenArea, settingsArea],
    player: [roomsArea, pastRoomsArea, playerSettingsArea],
};

/** The navigation of an area; none before the role choice. */
export function navAreasFor(area: Role | null): NavArea[] {
    return area === null ? [] : navAreas[area];
}

/**
 * The name of an area's home, as a link to `/start` reads on a page outside
 * the shell; «Siniflər» before the role choice.
 */
export function areaHomeTitle(area: Role | null): string {
    return area === null ? classesArea.title : navAreas[area][0].title;
}

/** The navigation of the current page's area (the shared `auth.area`). */
export function useNavAreas(): NavArea[] {
    return navAreasFor(usePage().props.auth.area);
}
