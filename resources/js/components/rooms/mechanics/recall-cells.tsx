import type { CSSProperties } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine, RoomStudy } from '@/rooms/contract';

/*
 * «Xanaları yadda saxla» on the room page (docs/architecture.md §12 "Room
 * games", B4): the grid with its lit cells to study, the dark grid to tap
 * the cells that were lit, and the cells that were. The study holds the lit
 * cells only and is drawn only from a state in its study phase; the
 * question is every cell of the grid, the same whichever were lit, and
 * only `reveal.solution` says which. Plain on purpose; B4's interface
 * session designs it.
 */

type Grid = { cols: number; rows: number };

function gridStyle(grid: Grid): CSSProperties {
    return {
        gridTemplateColumns: `repeat(${grid.cols}, minmax(0, 1fr))`,
        maxWidth: `${grid.cols * 4}rem`,
    };
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

/** The grid with its lit cells; drawn only from a state in its study phase. */
export function RecallCellsStudy({ study }: { study: RoomStudy }) {
    const grid = study.grid ?? { cols: 1, rows: 1 };
    const lit = new Set(study.items.map(place));

    return (
        <div className="grid w-full gap-1.5" style={gridStyle(grid)}>
            {places(grid).map((key) => (
                <span
                    key={key}
                    className={cn(
                        'aspect-square rounded-md border',
                        lit.has(key) ? 'bg-primary' : 'bg-card',
                    )}
                />
            ))}
        </div>
    );
}

/**
 * The dark grid: each cell is an item of the question, in the server's
 * order; a tap adds a cell to the answer, which is sent once it holds
 * `ask.pick` cells.
 */
export function RecallCellsAsk({
    ask,
    picked,
    disabled,
    onPick,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
}) {
    const { t } = useTranslation();
    const grid = ask.grid ?? { cols: 1, rows: 1 };

    return (
        <div className="flex w-full flex-col items-center gap-2">
            <div className="grid w-full gap-1.5" style={gridStyle(grid)}>
                {ask.items.map((item) => {
                    const chosen = picked.includes(item.id);

                    return (
                        <button
                            key={item.id}
                            type="button"
                            aria-pressed={chosen}
                            disabled={disabled || chosen}
                            onClick={() => onPick(item.id)}
                            className={cn(
                                'aspect-square rounded-md border transition-colors',
                                chosen ? 'bg-primary' : 'bg-card',
                                !disabled && !chosen && 'hover:bg-accent',
                            )}
                        />
                    );
                })}
            </div>
            <span className="text-sm text-muted-foreground tabular-nums">
                {t('rooms.game.picked', {
                    count: picked.length,
                    total: ask.pick,
                })}
            </span>
        </div>
    );
}

/** The cells that were lit, from the reveal, and the viewer's own taps on them. */
export function RecallCellsSolution({
    ask,
    solution,
    mine,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
}) {
    const { t } = useTranslation();
    const grid = ask.grid ?? { cols: 1, rows: 1 };
    const right = new Set(solution);
    const tapped = new Set(mine?.picked ?? []);

    return (
        <div className="flex w-full flex-col items-center gap-2">
            <span className="text-sm text-muted-foreground">
                {t('rooms.game.lit_cells')}
            </span>
            <div className="grid w-full gap-1.5" style={gridStyle(grid)}>
                {ask.items.map((item) => (
                    <span
                        key={item.id}
                        className={cn(
                            'flex aspect-square items-center justify-center rounded-md border text-xs',
                            right.has(item.id) ? 'bg-primary' : 'bg-card',
                            tapped.has(item.id) &&
                                !right.has(item.id) &&
                                'border-destructive bg-destructive/20',
                        )}
                    >
                        {tapped.has(item.id) && '•'}
                    </span>
                ))}
            </div>
            {mine !== null && (
                <span className="text-xs text-muted-foreground">
                    {t('rooms.game.yours_marked')}
                </span>
            )}
        </div>
    );
}
