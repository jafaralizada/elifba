import type { RoomState } from '@/rooms/contract';
import type { StorageLike } from '@/rooms/sound';

/*
 * Taunts on a page (docs/frontend.md rule 15 "Taunts", ADR 029 decisions
 * 17–24), the part that is pure: which taunts a page shows now, which of
 * them it saw arrive, what the tray says, and this device's choice to hide
 * them. Everything else is the server's: whether a throw is open, the
 * balance, the cooldown's end and which taunts a state holds. Nothing here
 * reads a verdict, a solution or anybody's result — only the state's
 * `taunts`, the round's index and phase, and the strip's rival.
 */

/**
 * How long a taunt is shown after its throw, by the server's clock: the
 * server's `elifba.rooms.taunts.show_ms`, for which a state lists it
 * (`RoomTauntsTest` holds the two equal).
 */
export const TAUNT_SHOW_MS = 4_000;

/** How long «Atıldı · −1 xal» lies over the strip after an accepted throw. */
export const TAUNT_SENT_MS = 2_000;

/** At most two cards at once; a third waits for a place. */
export const TAUNT_CARDS = 2;

/** This device's choice, one key per kind of device, like the mute: never sent. */
export const TAUNTS_HIDDEN_KEYS = {
    phone: 'elifba.rooms.phone.taunts_hidden',
    screen: 'elifba.rooms.screen.taunts_hidden',
} as const;

export type TauntDevice = keyof typeof TAUNTS_HIDDEN_KEYS;

/** Whether this device hides the taunts aimed at it (or, on the screen, every one); false when storage cannot say. */
export function readTauntsHidden(
    storage: StorageLike | null,
    device: TauntDevice,
): boolean {
    try {
        return storage?.getItem(TAUNTS_HIDDEN_KEYS[device]) === '1';
    } catch {
        return false;
    }
}

/** Remembers the choice on this device; a refusal only means it is not remembered. */
export function writeTauntsHidden(
    storage: StorageLike | null,
    device: TauntDevice,
    hidden: boolean,
): void {
    try {
        if (hidden) {
            storage?.setItem(TAUNTS_HIDDEN_KEYS[device], '1');
        } else {
            storage?.removeItem(TAUNTS_HIDDEN_KEYS[device]);
        }
    } catch {
        // Not remembered: the choice holds until the page is left.
    }
}

/**
 * The taunts a page shows at `nowMs` (the server's clock as the page
 * estimates it): the state's, until `sent_ms + TAUNT_SHOW_MS`, the two that
 * came first — a third waits for one of them to go — and none while this
 * device hides them or before the page knows the server's clock.
 */
export function shownTaunts<T extends { id: string; sent_ms: number }>(
    list: readonly T[] | null | undefined,
    nowMs: number | null,
    hidden: boolean,
): T[] {
    if (hidden || nowMs === null || list === null || list === undefined) {
        return [];
    }

    return list
        .filter((taunt) => taunt.sent_ms + TAUNT_SHOW_MS > nowMs)
        .sort((a, b) => a.sent_ms - b.sent_ms || (a.id < b.id ? -1 : 1))
        .slice(0, TAUNT_CARDS);
}

/** The latest moment any of these taunts is still shown, or null: when the page draws again. */
export function tauntsEnd(
    list: readonly { sent_ms: number }[] | null | undefined,
): number | null {
    if (list === null || list === undefined || list.length === 0) {
        return null;
    }

    return Math.max(...list.map((taunt) => taunt.sent_ms + TAUNT_SHOW_MS));
}

/** The taunts a page has drawn, by id, and whether it has drawn its first state. */
export type TauntsSeen = { opened: boolean; ids: ReadonlySet<string> };

export const TAUNTS_UNSEEN: TauntsSeen = { opened: false, ids: new Set() };

/**
 * Which of a state's taunts the page saw arrive (ADR 029 decision 24): one
 * in the first state the page drew was there before the page — it is drawn
 * still for the time it has left; any other arrives the first time a state
 * holds it; a state fetched again brings nothing new.
 */
export function tauntMoment(
    seen: TauntsSeen,
    ids: readonly string[],
): { seen: TauntsSeen; arrived: string[] } {
    const known = new Set(seen.ids);
    const arrived = seen.opened ? ids.filter((id) => !known.has(id)) : [];

    ids.forEach((id) => known.add(id));

    return { seen: { opened: true, ids: known }, arrived };
}

/** Why the tray cannot throw now; null when it can. */
export type TrayReason = 'first_round' | 'no_points' | 'cooling';

export type TrayView =
    | { kind: 'none' }
    | {
          kind: 'tray';
          balance: number;
          reason: TrayReason | null;
          /** Whole seconds to the end of the cooldown while it runs, by the server's clock. */
          cooling: number | null;
      };

/**
 * What the tray beside the strip shows (ADR 029 decisions 4, 17 and 18):
 * nothing unless the viewer plays, a throw is open (`taunts.open`: the
 * host's switch on, before the last close) and the strip has a rival to
 * throw at; else the viewer's balance, and why a throw would not be taken
 * now — before the first close, no points, or the cooldown with its
 * seconds. It reads the state's `taunts` and the round's index and phase,
 * never an answer, a result or the ranking.
 */
export function trayView(
    state: RoomState,
    rival: string | null,
    nowMs: number | null,
): TrayView {
    const taunts = state.taunts;

    if (
        taunts === null ||
        taunts.balance === null ||
        !taunts.open ||
        rival === null
    ) {
        return { kind: 'none' };
    }

    const left =
        taunts.ready_ms === null || nowMs === null
            ? 0
            : taunts.ready_ms - nowMs;
    const cooling = left > 0 ? Math.ceil(left / 1000) : null;
    const beforeFirstClose =
        state.round !== null &&
        state.round.index === 0 &&
        state.round.phase !== 'reveal';
    let reason: TrayReason | null = null;

    if (taunts.balance < 1) {
        reason = beforeFirstClose ? 'first_round' : 'no_points';
    } else if (cooling !== null) {
        reason = 'cooling';
    }

    return { kind: 'tray', balance: taunts.balance, reason, cooling };
}
