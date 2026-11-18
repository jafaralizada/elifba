import { Check } from 'lucide-react';
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
 * lamp in both themes, and only in the study; the viewer's taps are the
 * primary colour. The reveal draws the cells that were lit as the right
 * answer — green, with a check — and rings the viewer's taps; whether the
 * answer was right is the server's word (`mine.correct`).
 *
 * Motion (ADR 025): the lit cells switch on together; at `answer_from_ms`
 * they go dark at once, so the dark grid the question brings is already
 * there; a tap presses its cell; in the reveal the right cells' checks pop.
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

/** A cell that was lit, as the reveal draws it: the right answer, green with a check — never the study's lamp. */
const RIGHT =
    'border-emerald-600 bg-emerald-600/15 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-400/15 dark:text-emerald-300';
/** A cell that was not lit, in the reveal: only its outline, neither the study's nor the question's dark cell. */
const EMPTY = 'border-dashed border-muted-foreground/35 bg-transparent';
/** The viewer's own tap, in the reveal: a ring of the viewer's colour, the same on a right cell and a wrong one. */
const YOURS = 'ring-[3px] ring-primary ring-inset';

/**
 * The cells that were lit, from the reveal, as the round's right answer:
 * under «Düzgün xanalar» with `Check`, each such cell green with a check,
 * the others only outlined, and the viewer's own taps ringed in their
 * colour. It shares nothing with the study's look — no amber, no glow, no
 * lamp — so a player who did not answer never takes it for the next
 * round's cells to remember (the owner's report from elifba.io,
 * 2026-11-18).
 */
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
    const right = new Set(solution);
    const tapped = new Set(mine?.picked ?? []);

    return (
        <div
            data-solution
            className={cn(
                'flex w-full flex-col items-center',
                big ? 'gap-[2vh]' : 'gap-2',
            )}
        >
            <span
                className={cn(
                    'flex items-center font-semibold text-emerald-700 dark:text-emerald-300',
                    big ? 'gap-[1vh] text-[3vh]' : 'gap-1.5 text-sm',
                )}
            >
                <Check
                    aria-hidden
                    strokeWidth={3}
                    className={big ? 'size-[3.2vh]' : 'size-4'}
                />
                {t('rooms.game.right_cells')}
            </span>
            <div
                className={cn('mx-auto grid', gap(grid, big))}
                style={gridStyle(grid, big)}
            >
                {ask.items.map((item) => (
                    <span
                        key={item.id}
                        data-cell={place(item)}
                        data-right={right.has(item.id) ? '' : undefined}
                        data-yours={tapped.has(item.id) ? '' : undefined}
                        className={cn(
                            CELL,
                            'flex items-center justify-center',
                            right.has(item.id) ? RIGHT : EMPTY,
                            tapped.has(item.id) && YOURS,
                        )}
                    >
                        {right.has(item.id) && (
                            <Check
                                aria-hidden
                                strokeWidth={3}
                                style={{
                                    animationDelay: `${REVEAL_MS.highlight}ms`,
                                }}
                                className="size-[46%] room-motion:animate-room-pop"
                            />
                        )}
                    </span>
                ))}
            </div>
            {mine !== null && (
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span
                        aria-hidden
                        className={cn(
                            'inline-block size-3.5 rounded-[18%]',
                            YOURS,
                        )}
                    />
                    {t('rooms.game.yours_marked')}
                </span>
            )}
        </div>
    );
}
