import {
    ChoiceOptions,
    ChoiceReveal,
    itemText,
} from '@/components/rooms/mechanics/choice';
import {
    longestText,
    rowFont,
} from '@/components/rooms/mechanics/choice-layout';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine } from '@/rooms/contract';

/*
 * «Sıranı davam etdir» (docs/architecture.md §12 "Room games",
 * docs/frontend.md rule 15): the row of numbers (`ask.shown`, never an
 * answer) on one line, with a dashed «?» in the place of the next one,
 * and the four options under it (`ask.items`). The reveal writes the
 * right option into that place, from `reveal.solution`, and marks the
 * options under the row as the reveal says. The page never works out a
 * term: the right one is the server's word.
 */

/**
 * The row and its last place, equal cells on one line whatever the
 * width: the text is sized to the row's container (`rowFont()`), so six
 * three-digit terms and the «?» fit a 375px phone.
 */
function Row({
    shown,
    next,
    big,
}: {
    shown: RoomItem[];
    next: RoomItem | undefined;
    big: boolean;
}) {
    const { t } = useTranslation();
    const cells = shown.length + 1;
    const gap = big ? '1.2vh' : '0.25rem';
    const font = rowFont(
        cells,
        longestText(next === undefined ? shown : [...shown, next]),
        gap,
        big ? '7vh' : '1.5rem',
    );
    const cell = cn(
        'flex min-w-0 items-center justify-center border font-semibold tabular-nums',
        big ? 'h-[11vh] rounded-[1.6vh]' : 'h-12 rounded-xl',
    );

    return (
        <div className="@container w-full">
            <ol
                aria-label={t('rooms.game.row')}
                className="grid w-full"
                style={{
                    gridTemplateColumns: `repeat(${cells}, minmax(0, 1fr))`,
                    gap,
                    fontSize: font,
                }}
            >
                {shown.map((item) => (
                    <li key={item.id} className={cn(cell, 'bg-card')}>
                        {itemText(item)}
                    </li>
                ))}
                <li
                    className={cn(
                        cell,
                        next === undefined
                            ? 'border-2 border-dashed border-primary/60 bg-primary/5 text-primary'
                            : 'border-2 border-emerald-600 bg-emerald-600/10 text-emerald-700 dark:border-emerald-400 dark:text-emerald-300',
                    )}
                >
                    <span className="sr-only">
                        {t('rooms.game.next_term')}:{' '}
                    </span>
                    {next === undefined ? '?' : itemText(next)}
                </li>
            </ol>
        </div>
    );
}

export function NextInSequenceAsk({
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

    return (
        <div
            className={cn(
                'flex w-full flex-col items-center',
                big ? 'gap-[5vh]' : 'gap-5',
            )}
        >
            <Row shown={ask.shown} next={undefined} big={big} />
            <ChoiceOptions
                ask={ask}
                picked={picked}
                disabled={disabled}
                onPick={onPick}
                label={t('rooms.mechanics.next_in_sequence.ask')}
                big={big}
            />
        </div>
    );
}

export function NextInSequenceSolution({
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
    const right = ask.items.find((item) => item.id === solution[0]);

    return (
        <div
            className={cn(
                'flex w-full flex-col items-center',
                big ? 'gap-[4vh]' : 'gap-4',
            )}
        >
            <Row shown={ask.shown} next={right} big={big} />
            <ChoiceReveal ask={ask} solution={solution} mine={mine} big={big} />
        </div>
    );
}
