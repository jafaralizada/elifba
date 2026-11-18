import confetti from 'canvas-confetti';

/*
 * The movements of a room game that are the browser's (docs/frontend.md
 * rule 15 "Motion", ADR 025): a tap's press, a ranking's rows moving
 * between two orders the server gave, and the final's confetti. Each one
 * is skipped under `prefers-reduced-motion`, and none changes anything
 * but how an element is drawn for a moment.
 */

/** Whether the viewer asked for less motion; read when a movement starts, never while rendering. */
export function reducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

const EASE = 'cubic-bezier(0.2, 0.8, 0.2, 1)';

/** The viewer's own tap on an item: the same short press for every item and every tap. */
export function press(element: EventTarget | null): void {
    if (
        !(element instanceof HTMLElement) ||
        typeof element.animate !== 'function' ||
        reducedMotion()
    ) {
        return;
    }

    element.animate(
        [
            { transform: 'scale(0.9)' },
            { transform: 'scale(1.06)', offset: 0.6 },
            { transform: 'none' },
        ],
        { duration: 240, easing: EASE },
    );
}

/**
 * How much an element is drawn larger or smaller by CSS `zoom` (the
 * screen's `ScreenFit`): a movement's distances, measured on the page,
 * are divided by it, since a transform inside a zoomed element is zoomed.
 */
function zoomOf(element: Element): number {
    const zoom = (element as Element & { currentCSSZoom?: number })
        .currentCSSZoom;

    return typeof zoom === 'number' && zoom > 0 ? zoom : 1;
}

/**
 * A ranking's rows (`[data-player]` under `root`) move from where the
 * previous order had them to where they stand: `shift` is how many rows
 * each player moved up (`rankShift()`). The rows are as tall as each
 * other, so a row's old place is its shift times their pitch. A row of a
 * player the previous order did not hold comes in instead.
 */
export function slideRows(
    root: HTMLElement,
    shift: Map<string, number>,
    timing: { delay: number; duration: number },
): void {
    if (typeof root.animate !== 'function' || reducedMotion()) {
        return;
    }

    const rows = Array.from(
        root.querySelectorAll<HTMLElement>('[data-player]'),
    );

    if (rows.length === 0) {
        return;
    }

    const tops = rows.map((row) => row.getBoundingClientRect().top);
    const pitch =
        rows.length < 2
            ? 0
            : (tops[tops.length - 1] - tops[0]) /
              (rows.length - 1) /
              zoomOf(rows[0]);

    for (const row of rows) {
        const moved = shift.get(row.dataset.player ?? '');

        if (moved === undefined) {
            row.animate([{ opacity: 0 }, { opacity: 1 }], {
                ...timing,
                easing: EASE,
                fill: 'backwards',
            });

            continue;
        }

        if (moved === 0 || pitch === 0) {
            continue;
        }

        // Over the rows it passes, the one that moves.
        row.style.zIndex = '1';
        row.animate(
            [
                { transform: `translateY(${moved * pitch}px)` },
                { transform: 'none' },
            ],
            { ...timing, easing: EASE, fill: 'backwards' },
        ).onfinish = () => {
            row.style.zIndex = '';
        };
    }
}

/**
 * The rows of a ranking laid out in any grid — the screen's race lanes, in
 * one column or two — move from the slot the previous order had them in
 * to their own: the slot of index `i + shift` is where the row at index
 * `i` stood (`rankShift()`). A row of a player the previous order did not
 * hold comes in instead.
 */
export function slideSlots(
    root: HTMLElement,
    shift: Map<string, number>,
    timing: { delay: number; duration: number },
): void {
    if (typeof root.animate !== 'function' || reducedMotion()) {
        return;
    }

    const rows = Array.from(
        root.querySelectorAll<HTMLElement>('[data-player]'),
    );
    const slots = rows.map((row) => row.getBoundingClientRect());

    rows.forEach((row, index) => {
        const moved = shift.get(row.dataset.player ?? '');

        if (moved === undefined) {
            row.animate([{ opacity: 0 }, { opacity: 1 }], {
                ...timing,
                easing: EASE,
                fill: 'backwards',
            });

            return;
        }

        const from = slots[index + moved];

        if (moved === 0 || from === undefined) {
            return;
        }

        const dx = (from.left - slots[index].left) / zoomOf(row);
        const dy = (from.top - slots[index].top) / zoomOf(row);

        // Over the rows it passes, the one that moves.
        row.style.zIndex = '1';
        row.animate(
            [
                { transform: `translate(${dx}px, ${dy}px)` },
                { transform: 'none' },
            ],
            { ...timing, easing: EASE, fill: 'backwards' },
        ).onfinish = () => {
            row.style.zIndex = '';
        };
    });
}

/** The final's colours: the room's amber with a few others, never the verdict's green or red. */
const COLOURS = [
    '#fbbf24',
    '#f59e0b',
    '#fde68a',
    '#60a5fa',
    '#a78bfa',
    '#f472b6',
];

/**
 * The final's confetti, once, when the first place opens: from both sides
 * and the middle on the screen, a small burst on a winner's phone.
 * `canvas-confetti` draws nothing under reduced motion.
 */
export function celebrate(where: 'screen' | 'phone'): void {
    const common = {
        colors: COLOURS,
        disableForReducedMotion: true,
        ticks: 120,
        zIndex: 60,
    };

    if (where === 'phone') {
        void confetti({
            ...common,
            particleCount: 70,
            spread: 80,
            startVelocity: 36,
            origin: { y: 0.35 },
        });

        return;
    }

    void confetti({
        ...common,
        particleCount: 90,
        angle: 60,
        spread: 65,
        startVelocity: 60,
        origin: { x: 0, y: 0.8 },
    });
    void confetti({
        ...common,
        particleCount: 90,
        angle: 120,
        spread: 65,
        startVelocity: 60,
        origin: { x: 1, y: 0.8 },
    });
    void confetti({
        ...common,
        particleCount: 60,
        spread: 110,
        startVelocity: 35,
        origin: { x: 0.5, y: 0.3 },
    });
}
