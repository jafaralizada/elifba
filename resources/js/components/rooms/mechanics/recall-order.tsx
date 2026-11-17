import type { ReactNode } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine, RoomStudy } from '@/rooms/contract';

/*
 * «Ardıcıllığı yadda saxla» (docs/architecture.md §12 "Room games",
 * docs/frontend.md rule 15): the row to study, the question to tap in
 * order, and the order that was right. Every item is drawn as the server
 * sent it — a text is content, cased on the server; a shape is its
 * picture, from the app's own `/content` — and nothing here knows the
 * order before the reveal: the question comes shuffled, and only
 * `reveal.solution` says which way it goes. The viewer's answer is shown
 * beside the right order, never compared with it item by item: right or
 * wrong is the server's word (`mine.correct`).
 */

/** The text sizes of a face: a tile on a phone, a slot, a tile on the screen. */
const TEXT = {
    tile: 'text-3xl',
    small: 'text-xl',
    slot: 'text-lg',
    big: 'text-[min(7vh,5vw)]',
    bigSmall: 'text-[min(5vh,3.6vw)]',
} as const;

/** An item's face: its text, or its picture. */
function face(item: RoomItem, size: keyof typeof TEXT): ReactNode {
    if (item.asset !== null) {
        return (
            <img
                src={item.asset}
                alt=""
                draggable={false}
                className="pointer-events-none size-[78%] object-contain select-none"
            />
        );
    }

    return (
        <span
            className={cn('font-semibold tabular-nums select-none', TEXT[size])}
        >
            {item.text ?? ''}
        </span>
    );
}

/** A tile's box: 60px on a phone (five to a row at 375px), up to 12vh on the screen. */
function tileSize(big: boolean): string {
    return big
        ? 'size-[min(13vh,9vw)] rounded-[min(2.5vh,1.8vw)]'
        : 'size-15 rounded-2xl';
}

/** The row in the order shown; drawn only from a state in its study phase. */
export function RecallOrderStudy({
    study,
    big = false,
}: {
    study: RoomStudy;
    big?: boolean;
}) {
    return (
        <ol
            className={cn(
                'flex flex-wrap justify-center',
                big ? 'gap-[1.6vh]' : 'gap-2',
            )}
        >
            {study.items.map((item) => (
                <li
                    key={item.id}
                    className={cn(
                        'flex items-center justify-center border bg-card shadow-sm',
                        tileSize(big),
                    )}
                >
                    {face(item, big ? 'big' : 'tile')}
                </li>
            ))}
        </ol>
    );
}

/**
 * The question's items in the order the server sent them, under a row of
 * slots that fills in the order tapped: each tap puts the item into the
 * next slot and dims it with its number; «Geri» (on the page) takes back
 * the last. On the screen the same items, with no slots and no taps.
 */
export function RecallOrderAsk({
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
    const items = new Map(ask.items.map((item) => [item.id, item]));

    if (big) {
        return (
            <RecallOrderStudy
                study={{ layout: 'row', grid: null, items: ask.items }}
                big
            />
        );
    }

    return (
        <div className="flex w-full flex-col items-center gap-4">
            <ol
                aria-label={t('rooms.game.yours')}
                className="grid w-full justify-center gap-1.5"
                style={{
                    gridTemplateColumns: `repeat(${ask.pick}, minmax(0, 2.75rem))`,
                }}
            >
                {Array.from({ length: ask.pick }, (_, index) => {
                    const item = items.get(picked[index] ?? '');

                    return (
                        <li
                            key={index}
                            className={cn(
                                'flex aspect-square items-center justify-center rounded-lg text-lg',
                                item === undefined
                                    ? 'border border-dashed border-muted-foreground/40'
                                    : 'border bg-card shadow-xs',
                            )}
                        >
                            {item !== undefined && face(item, 'slot')}
                        </li>
                    );
                })}
            </ol>

            <div className="flex flex-wrap justify-center gap-2">
                {ask.items.map((item) => {
                    const position = picked.indexOf(item.id);
                    const used = position !== -1;

                    return (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled || used}
                            aria-pressed={used}
                            onClick={() => onPick(item.id)}
                            className={cn(
                                'relative flex items-center justify-center border bg-card shadow-sm transition-[opacity,transform] outline-none select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100',
                                tileSize(false),
                                used && 'opacity-35 shadow-none',
                                disabled && !used && 'opacity-60',
                            )}
                        >
                            {face(item, 'tile')}
                            {used && (
                                <span className="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground tabular-nums">
                                    {position + 1}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/** A list of ids as the question's items say them, in that order. */
function Row({
    ask,
    ids,
    big,
    label,
}: {
    ask: RoomAsk;
    ids: string[];
    big: boolean;
    label: string;
}) {
    const items = new Map(ask.items.map((item) => [item.id, item]));

    return (
        <div className="flex flex-col items-center gap-1.5">
            <span
                className={cn(
                    'text-sm text-muted-foreground',
                    big && 'text-[2.4vh]',
                )}
            >
                {label}
            </span>
            <ol
                className={cn(
                    'flex flex-wrap justify-center',
                    big ? 'gap-[1.2vh]' : 'gap-1.5',
                )}
            >
                {ids.map((id, index) => {
                    const item = items.get(id);

                    return (
                        <li
                            key={`${id}-${index}`}
                            className={cn(
                                'flex items-center justify-center border bg-card',
                                big
                                    ? 'size-[min(10vh,7vw)] rounded-[1.6vh]'
                                    : 'size-11 rounded-xl',
                            )}
                        >
                            {item === undefined
                                ? ''
                                : face(item, big ? 'bigSmall' : 'small')}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

/** The right order, from the reveal, and the viewer's own answer under it. */
export function RecallOrderSolution({
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

    return (
        <div
            className={cn(
                'flex flex-col items-center',
                big ? 'gap-[3vh]' : 'gap-3',
            )}
        >
            <Row
                ask={ask}
                ids={solution}
                big={big}
                label={t('rooms.game.solution')}
            />
            {mine !== null && (
                <Row
                    ask={ask}
                    ids={mine.picked}
                    big={big}
                    label={t('rooms.game.yours')}
                />
            )}
        </div>
    );
}
