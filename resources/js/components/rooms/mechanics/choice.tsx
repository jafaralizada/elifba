import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomItem, RoomMine } from '@/rooms/contract';

/*
 * The options of a `choice` round (docs/architecture.md §12 "Room games"),
 * shared by the logic games' views: each option a button, drawn as the
 * server sent it and in its order — a number or a word is content, cased
 * on the server — and the right one only from `reveal.solution`. A tap
 * chooses and sends (`pick` is 1); nothing here knows which is right.
 * Plain on purpose: the next session designs them.
 */

/** Nothing is studied in a logic round; the view has no study. */
export function NoStudy() {
    return null;
}

/** An item's text, as the server cased it. */
export function itemText(item: RoomItem | undefined): string {
    return item?.text ?? '';
}

/** The options to tap; on the screen the same options, with no taps. */
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
            <ul className="grid w-full grid-cols-2 gap-[1.6vh]">
                {ask.items.map((item) => (
                    <li
                        key={item.id}
                        className="flex min-h-[10vh] items-center justify-center rounded-[1.6vh] border bg-card px-[2vh] text-[min(6vh,4vw)] font-semibold tabular-nums"
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
            className="grid w-full grid-cols-2 gap-2"
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
                            'flex min-h-14 items-center justify-center rounded-2xl border bg-card px-3 text-2xl font-semibold tabular-nums shadow-sm transition-transform outline-none select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100',
                            chosen &&
                                'border-primary bg-primary text-primary-foreground',
                            disabled && !chosen && 'opacity-60',
                        )}
                    >
                        {itemText(item)}
                    </button>
                );
            })}
        </div>
    );
}

/** The right option, from the reveal, and the viewer's own choice under it. */
export function ChoiceSolution({
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
    const items = new Map(ask.items.map((item) => [item.id, item]));

    return (
        <dl
            className={cn(
                'flex flex-col items-center text-center',
                big ? 'gap-[2vh]' : 'gap-2',
            )}
        >
            <div className="flex flex-col items-center gap-1">
                <dt
                    className={cn(
                        'text-sm text-muted-foreground',
                        big && 'text-[2.4vh]',
                    )}
                >
                    {t('rooms.game.right_option')}
                </dt>
                <dd
                    className={cn(
                        'font-semibold tabular-nums',
                        big ? 'text-[min(7vh,5vw)]' : 'text-3xl',
                    )}
                >
                    {itemText(items.get(solution[0] ?? ''))}
                </dd>
            </div>
            {mine !== null && (
                <div className="flex flex-col items-center gap-1">
                    <dt
                        className={cn(
                            'text-sm text-muted-foreground',
                            big && 'text-[2.4vh]',
                        )}
                    >
                        {t('rooms.game.yours')}
                    </dt>
                    <dd
                        className={cn(
                            'font-semibold tabular-nums',
                            big ? 'text-[min(5vh,3.6vw)]' : 'text-xl',
                        )}
                    >
                        {itemText(items.get(mine.picked[0] ?? ''))}
                    </dd>
                </div>
            )}
        </dl>
    );
}
