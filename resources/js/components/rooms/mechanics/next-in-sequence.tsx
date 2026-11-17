import {
    ChoiceOptions,
    ChoiceSolution,
    itemText,
} from '@/components/rooms/mechanics/choice';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine } from '@/rooms/contract';

/*
 * «Sıranı davam etdir» (docs/architecture.md §12 "Room games"): the row of
 * numbers (`ask.shown`, never an answer) with a «?» where the next one
 * goes, and four options to choose it from (`ask.items`). The reveal puts
 * the right option in the row. Plain: the next session designs it.
 */

/** The row and its last place: a «?» while asked, the right number in the reveal. */
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
    const cell = cn(
        'flex items-center justify-center border font-semibold tabular-nums',
        big
            ? 'h-[11vh] min-w-[11vh] rounded-[1.6vh] px-[1vh] text-[min(6vh,4vw)]'
            : 'h-11 min-w-11 rounded-lg px-1.5 text-lg',
    );

    return (
        <ol
            className={cn(
                'flex flex-wrap justify-center',
                big ? 'gap-[1.2vh]' : 'gap-1.5',
            )}
        >
            {shown.map((item) => (
                <li key={item.id} className={cn(cell, 'bg-card')}>
                    {itemText(item)}
                </li>
            ))}
            <li
                aria-label={t('rooms.game.next_term')}
                className={cn(
                    cell,
                    next === undefined
                        ? 'border-dashed border-muted-foreground/50 text-muted-foreground'
                        : 'border-primary bg-primary text-primary-foreground',
                )}
            >
                {next === undefined ? '?' : itemText(next)}
            </li>
        </ol>
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
                big ? 'gap-[4vh]' : 'gap-5',
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
                big ? 'gap-[3vh]' : 'gap-3',
            )}
        >
            <Row shown={ask.shown} next={right} big={big} />
            <ChoiceSolution
                ask={ask}
                solution={solution}
                mine={mine}
                big={big}
            />
        </div>
    );
}
