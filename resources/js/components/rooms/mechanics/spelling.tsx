import { Mark, TILE } from '@/components/rooms/mechanics/choice';
import { optionMark } from '@/components/rooms/mechanics/choice-layout';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * «Düzgün yazılış» (docs/architecture.md §12 "Room games",
 * docs/frontend.md rule 15): four spellings of one word, each a button of
 * its own, in the order the server sent them — the right one's place is
 * the server's seed — and one tap chooses and sends. Unlike the logic
 * games' options they stand in one column, full width, so each spelling
 * is set large (28px on a phone, up to 7vh on the screen) and the four
 * read down the page letter under letter: «ı» and «i», «ə» and «e», «ş»
 * and «s» are what the round asks about. The reveal marks the right
 * spelling from `reveal.solution` and the viewer's own by the server's
 * verdict; nothing here knows the right one before it.
 *
 * Words are drawn as the server wrote them: no `uppercase` or
 * `capitalize` class and no `toUpperCase()`, which outside an Azerbaijani
 * locale would turn «i» into «I» and lose «ı» and «İ».
 */

const WORD = 'font-semibold tracking-wide';
const PHONE = 'min-h-14 rounded-2xl px-4 text-[1.75rem] leading-tight';
const BIG =
    'min-h-[11vh] rounded-[1.6vh] px-[3vh] text-[min(7vh,4.5vw)] leading-tight';
const COLUMN = 'mx-auto grid w-full grid-cols-1';

export function SpellingAsk({
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

    if (big) {
        return (
            <ul className={cn(COLUMN, 'max-w-[80vh] gap-[1.6vh]')}>
                {ask.items.map((item) => (
                    <li
                        key={item.id}
                        className={cn(TILE, WORD, BIG, 'bg-card shadow-sm')}
                    >
                        {item.text ?? ''}
                    </li>
                ))}
            </ul>
        );
    }

    return (
        <div
            role="group"
            aria-label={t('rooms.mechanics.spelling.ask')}
            className={cn(COLUMN, 'max-w-sm gap-2')}
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
                            WORD,
                            PHONE,
                            'bg-card py-2 shadow-sm transition-[background-color,opacity,transform] outline-none select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-[0.98] motion-reduce:transition-none motion-reduce:active:scale-100',
                            chosen &&
                                'border-primary bg-primary text-primary-foreground',
                            disabled && !chosen && 'opacity-50',
                        )}
                    >
                        {item.text ?? ''}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * The four spellings in their places, each with its mark line under it:
 * the right one green, «Düzgün»; the viewer's own «Sizin», green or red
 * as the server judged it, or «Düzgün · sizin»; the rest faded. On the
 * screen there is no viewer, so only «Düzgün».
 */
export function SpellingSolution({
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
    return (
        <ul
            className={cn(
                COLUMN,
                big ? 'max-w-[80vh] gap-[1.6vh]' : 'max-w-sm gap-2',
            )}
        >
            {ask.items.map((item) => {
                const mark = optionMark(item.id, solution, mine);

                return (
                    <li
                        key={item.id}
                        className={cn(
                            TILE,
                            WORD,
                            'flex-col gap-0.5',
                            big ? cn(BIG, 'py-[1vh]') : cn(PHONE, 'py-1.5'),
                            mark.tone === 'right' &&
                                'border-emerald-600 bg-emerald-600/10 dark:border-emerald-400',
                            mark.tone === 'wrong' &&
                                'border-red-600 bg-destructive/10 dark:border-red-400',
                            mark.tone === null &&
                                'bg-card text-muted-foreground opacity-70',
                        )}
                    >
                        <span className="min-w-0">{item.text ?? ''}</span>
                        <Mark mark={mark} big={big} keepLine />
                    </li>
                );
            })}
        </ul>
    );
}
