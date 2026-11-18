import type { CSSProperties } from 'react';
import { REVEAL_MS } from '@/components/rooms/motion';
import { press } from '@/components/rooms/motion-dom';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine, RoomStudy } from '@/rooms/contract';

/*
 * «Xanaları yadda saxla» (docs/architecture.md §12 "Room games",
 * docs/frontend.md rule 15): the grid with its lit cells to study, the
 * dark grid to tap the cells that were lit, and the cells that were. The
 * study holds the lit cells only and is drawn only from a state in its
 * study phase; the question is every cell of the grid, the same whichever
 * were lit, and only `reveal.solution` says which. A lit cell is amber, a
 * lamp in both themes; the viewer's taps are the primary colour. The
 * reveal draws the lit cells and marks the viewer's taps on them; whether
 * the answer was right is the server's word (`mine.correct`).
 *
 * Motion (ADR 025): the lit cells switch on together; at `answer_from_ms`
 * they go dark at once, so the dark grid the question brings is already
 * there; a tap presses its cell; the reveal switches the lit cells on
 * again and then marks the viewer's taps.
 */

type Grid = { cols: number; rows: number };

/**
 * The grid's columns and its width, square cells. On a phone as wide as
 * the page allows and no taller than what 375 × 667 leaves beside the
 * shell and the round's head and footer (`100svh − 22rem`), never with
 * cells under 44px; on the screen up to 62vh.
 */
function gridStyle(grid: Grid, big: boolean): CSSProperties {
    const side = Math.max(grid.cols, grid.rows);
    const least = grid.cols * 2.75 + (grid.cols - 1) * 0.375;

    return {
        gridTemplateColumns: `repeat(${grid.cols}, minmax(0, 1fr))`,
        width: big
            ? `min(${(62 * grid.cols) / side}vh, ${(46 * grid.cols) / side}vw)`
            : `max(${least}rem, min(100%, ${grid.cols * 4.75}rem, calc((100svh - 22rem) * ${grid.cols / grid.rows})))`,
    };
}

function gap(grid: Grid, big: boolean): string {
    if (big) {
        return 'gap-[1.2vh]';
    }

    return Math.max(grid.cols, grid.rows) > 4 ? 'gap-1.5' : 'gap-2';
}

/** `[col, row]` as one key. */
function place(item: RoomItem): string {
    return item.cell === null ? '' : `${item.cell[0]}:${item.cell[1]}`;
}

/** Every place of the grid, row by row. */
function places(grid: Grid): string[] {
    return Array.from(
        { length: grid.rows * grid.cols },
        (_, index) => `${index % grid.cols}:${Math.floor(index / grid.cols)}`,
    );
}

const CELL = 'aspect-square rounded-[18%] border';
const DARK = 'border-border bg-muted';
const LIT =
    'border-amber-500 bg-amber-400 shadow-[0_0_0.9rem_rgb(251_191_36/0.55)] dark:border-amber-300';

/**
 * The grid with its lit cells; drawn only from a state in its study phase
 * and, `covered`, all dark.
 */
export function RecallCellsStudy({
    study,
    big = false,
    covered = false,
}: {
    study: RoomStudy;
    big?: boolean;
    covered?: boolean;
}) {
    const grid = study.grid ?? { cols: 1, rows: 1 };
    const lit = new Set(covered ? [] : study.items.map(place));

    return (
        <div
            data-covered={covered ? '' : undefined}
            className={cn('mx-auto grid', gap(grid, big))}
            style={gridStyle(grid, big)}
        >
            {places(grid).map((key) => (
                <span
                    key={key}
                    data-cell={key}
                    data-lit={lit.has(key) ? '' : undefined}
                    className={cn(
                        CELL,
                        lit.has(key)
                            ? cn(LIT, 'room-motion:animate-room-lamp')
                            : DARK,
                    )}
                />
            ))}
        </div>
    );
}

/**
 * The dark grid: each cell is an item of the question, in the server's
 * order (reading order). A tap chooses a cell and a second tap takes it
 * back; the tap that makes `ask.pick` cells sends the answer (the page's
 * `tap()`). On the screen the same grid, with no taps.
 */
export function RecallCellsAsk({
    ask,
    picked,
    disabled,
    onPick,
    big = false,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const grid = ask.grid ?? { cols: 1, rows: 1 };

    if (big) {
        return (
            <div
                className={cn('mx-auto grid', gap(grid, true))}
                style={gridStyle(grid, true)}
            >
                {ask.items.map((item) => (
                    <span key={item.id} className={cn(CELL, DARK)} />
                ))}
            </div>
        );
    }

    return (
        <div
            role="group"
            aria-label={t(`rooms.mechanics.recall_cells.ask`)}
            className={cn('mx-auto grid', gap(grid, false))}
            style={gridStyle(grid, false)}
        >
            {ask.items.map((item) => {
                const chosen = picked.includes(item.id);

                return (
                    <button
                        key={item.id}
                        type="button"
                        aria-pressed={chosen}
                        data-cell={place(item)}
                        aria-label={t('rooms.game.cell', {
                            col: (item.cell?.[0] ?? 0) + 1,
                            row: (item.cell?.[1] ?? 0) + 1,
                        })}
                        disabled={disabled}
                        onClick={(event) => {
                            press(event.currentTarget);
                            onPick(item.id);
                        }}
                        className={cn(
                            CELL,
                            'min-h-11 transition-[background-color,transform] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100',
                            chosen
                                ? 'border-primary bg-primary'
                                : 'border-border bg-muted',
                            disabled && !chosen && 'opacity-60',
                        )}
                    />
                );
            })}
        </div>
    );
}

/** The cells that were lit, from the reveal, with the viewer's own taps marked on the grid. */
export function RecallCellsSolution({
    ask,
    solution,
    mine,
    big = false,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const grid = ask.grid ?? { cols: 1, rows: 1 };
    const lit = new Set(solution);
    const tapped = new Set(mine?.picked ?? []);

    return (
        <div
            className={cn(
                'flex w-full flex-col items-center',
                big ? 'gap-[2vh]' : 'gap-2',
            )}
        >
            <span
                className={cn(
                    'text-sm text-muted-foreground',
                    big && 'text-[2.4vh]',
                )}
            >
                {t('rooms.game.lit_cells')}
            </span>
            <div
                className={cn('mx-auto grid', gap(grid, big))}
                style={gridStyle(grid, big)}
            >
                {ask.items.map((item) => (
                    <span
                        key={item.id}
                        style={{ animationDelay: `${REVEAL_MS.solution}ms` }}
                        className={cn(
                            CELL,
                            'flex items-center justify-center',
                            lit.has(item.id)
                                ? cn(LIT, 'room-motion:animate-room-lamp')
                                : DARK,
                        )}
                    >
                        {tapped.has(item.id) && (
                            <span
                                aria-hidden
                                style={{
                                    animationDelay: `${REVEAL_MS.highlight}ms`,
                                }}
                                className="size-[34%] rounded-full bg-primary ring-2 ring-background room-motion:animate-room-pop"
                            />
                        )}
                    </span>
                ))}
            </div>
            {mine !== null && (
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span
                        aria-hidden
                        className="inline-block size-2.5 rounded-full bg-primary"
                    />
                    {t('rooms.game.yours_marked')}
                </span>
            )}
        </div>
    );
}
