import { Check, X } from 'lucide-react';
import {
    optionColumns,
    optionMark,
} from '@/components/rooms/mechanics/choice-layout';
import type { OptionMark } from '@/components/rooms/mechanics/choice-layout';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine } from '@/rooms/contract';

/*
 * The options of a `choice` round (docs/frontend.md rule 15, architecture
 * §12 "Room games"), shared by the logic games' views. Each option is a
 * button of one size, in the server's order and drawn as the server sent
 * it — a number or a word is content, cased on the server — two by two
 * when they pair up, else one column (`optionColumns()`). Before the
 * reveal nothing tells one option from another but its text. A tap
 * chooses and sends (`pick` is 1, the page's `tap()`): the chosen option
 * takes the viewer's colour and the others fade and stop taking taps.
 * The reveal draws the same options in the same places, the right one
 * marked from `reveal.solution` and the viewer's own by `mine.correct`.
 */

/** Nothing is studied in a logic round; the view has no study. */
export function NoStudy() {
    return null;
}

/** An item's text, as the server cased it. */
export function itemText(item: RoomItem | undefined): string {
    return item?.text ?? '';
}

/** The grid of the options: two columns or one, the same on a phone and on the screen. */
function gridClass(count: number, big: boolean): string {
    return cn(
        'grid w-full',
        optionColumns(count) === 2 ? 'grid-cols-2' : 'grid-cols-1',
        big ? 'gap-[1.6vh]' : 'gap-2',
        optionColumns(count) === 1 && (big ? 'max-w-[60vh]' : 'max-w-sm'),
        'mx-auto',
    );
}

/** An option's text size: words a step smaller, so the longest fits two to a row at 375px. */
function textClass(item: RoomItem, big: boolean): string {
    if (big) {
        return item.kind === 'word'
            ? 'text-[min(5vh,3vw)]'
            : 'text-[min(6vh,4vw)]';
    }

    return item.kind === 'word' ? 'text-xl' : 'text-2xl';
}

/** An option's height on the screen: a little lower in one column, so five fit beside the head. */
function bigHeight(count: number): string {
    return optionColumns(count) === 1 ? 'min-h-[8.5vh]' : 'min-h-[10vh]';
}

const TILE =
    'flex min-w-0 items-center justify-center border text-center font-semibold break-words tabular-nums';

/** The options to tap; on the screen (`big`) the same options, with no taps. */
export function ChoiceOptions({
    ask,
    picked,
    disabled,
    onPick,
    label,
    big = false,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
    label: string;
    big?: boolean;
}) {
    if (big) {
        return (
            <ul className={gridClass(ask.items.length, true)}>
                {ask.items.map((item) => (
                    <li
                        key={item.id}
                        className={cn(
                            TILE,
                            'rounded-[1.6vh] bg-card px-[2vh] shadow-sm',
                            bigHeight(ask.items.length),
                            textClass(item, true),
                        )}
                    >
                        {itemText(item)}
                    </li>
                ))}
            </ul>
        );
    }

    return (
        <div
            role="group"
            aria-label={label}
            className={gridClass(ask.items.length, false)}
        >
            {ask.items.map((item) => {
                const chosen = picked.includes(item.id);

                return (
                    <button
                        key={item.id}
                        type="button"
                        aria-pressed={chosen}
                        disabled={disabled}
                        onClick={() => onPick(item.id)}
                        className={cn(
                            TILE,
                            'min-h-13 rounded-2xl bg-card px-3 py-2 shadow-sm transition-[background-color,opacity,transform] outline-none select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100',
                            textClass(item, false),
                            chosen &&
                                'border-primary bg-primary text-primary-foreground',
                            disabled && !chosen && 'opacity-50',
                        )}
                    >
                        {itemText(item)}
                    </button>
                );
            })}
        </div>
    );
}

/** The words and the icon of a mark: «Düzgün», «Sizin», «Düzgün · sizin». */
function Mark({
    mark,
    big,
    keepLine,
}: {
    mark: OptionMark;
    big: boolean;
    /** Two to a row: an unmarked option keeps the mark's line, so the texts of a row stay level. */
    keepLine: boolean;
}) {
    const { t } = useTranslation();
    const line = cn(
        'flex shrink-0 items-center justify-center gap-1 font-medium',
        big ? 'text-[2.2vh]' : 'text-xs',
    );

    if (mark.tone === null) {
        return keepLine ? (
            <span aria-hidden className={cn(line, 'invisible')}>
                ·
            </span>
        ) : null;
    }

    const words = mark.right
        ? mark.yours
            ? t('rooms.game.mark_right_yours')
            : t('rooms.game.mark_right')
        : t('rooms.game.mark_yours');
    const Icon = mark.tone === 'right' ? Check : X;

    return (
        <span
            className={cn(
                line,
                mark.tone === 'right'
                    ? 'text-emerald-700 dark:text-emerald-300'
                    : 'text-red-600 dark:text-red-400',
            )}
        >
            <Icon
                aria-hidden
                className={big ? 'size-[2.4vh]' : 'size-3.5'}
                strokeWidth={3}
            />
            {words}
        </span>
    );
}

/**
 * The options as the reveal leaves them, each in its place: the right one
 * green with «Düzgün», the viewer's own with «Sizin» (green or red, as the
 * server judged it), the rest faded. On the screen there is no viewer.
 */
export function ChoiceReveal({
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
    const one = optionColumns(ask.items.length) === 1;

    return (
        <ul className={gridClass(ask.items.length, big)}>
            {ask.items.map((item) => {
                const mark = optionMark(item.id, solution, mine);

                return (
                    <li
                        key={item.id}
                        className={cn(
                            TILE,
                            one ? 'flex-row gap-3' : 'flex-col gap-0.5',
                            big
                                ? cn(
                                      'rounded-[1.6vh] px-[2vh] py-[1vh]',
                                      bigHeight(ask.items.length),
                                  )
                                : 'min-h-13 rounded-2xl px-3 py-1.5',
                            textClass(item, big),
                            mark.tone === 'right' &&
                                'border-emerald-600 bg-emerald-600/10 dark:border-emerald-400',
                            mark.tone === 'wrong' &&
                                'border-red-600 bg-destructive/10 dark:border-red-400',
                            mark.tone === null &&
                                'bg-card text-muted-foreground opacity-70',
                        )}
                    >
                        <span className="min-w-0">{itemText(item)}</span>
                        <Mark mark={mark} big={big} keepLine={!one} />
                    </li>
                );
            })}
        </ul>
    );
}
