import type { CSSProperties, ReactNode } from 'react';
import { Mark } from '@/components/rooms/mechanics/choice';
import {
    anagramMine,
    lettersOf,
    slotText,
    tileRows,
} from '@/components/rooms/mechanics/word-layout';
import { REVEAL_MS, stagger } from '@/components/rooms/motion';
import { press } from '@/components/rooms/motion-dom';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * «Anaqram» (docs/architecture.md §12 "Room games", docs/frontend.md rule
 * 15): the word's letters as the server sent them — cased there (Az::upper,
 * so «İ» and «I» stay two letters in every locale), shuffled there, never
 * in the order of a word they spell — under a row of places, one for each
 * letter. Each tap moves a letter into the next place and leaves a dashed
 * gap where its tile stood; «Geri» (on the page) takes back the last. The
 * tap that fills the last place sends the word. Nothing here knows the
 * word before the reveal, and right or wrong is the server's word
 * (`mine.correct`): another word the letters spell is right too, and its
 * reveal says so rather than drawing it as a mistake.
 *
 * No case is changed here: no `uppercase` class, no `toUpperCase()`.
 *
 * Motion (ADR 025): the places come in and the tiles pop in; a tap presses
 * its tile and pops its letter into the place, and the next empty place
 * breathes; the reveal writes the word letter by letter.
 */

/** A tile: 60px on a phone (five to a row at 375px), up to 13vh on the screen. */
const TILE = { phone: 60, phoneGap: 8 } as const;
const BIG_TILE = 'min(13vh,9vw)';
const BIG_GAP = '1.6vh';

/** The tiles' box, as wide as `perRow` tiles, so they wrap into even rows. */
function tilesWidth(count: number, big: boolean): CSSProperties {
    const { perRow } = tileRows(count, big ? 6 : 5);

    return {
        maxWidth: big
            ? `calc(${perRow} * ${BIG_TILE} + ${perRow - 1} * ${BIG_GAP})`
            : `${perRow * TILE.phone + (perRow - 1) * TILE.phoneGap}px`,
    };
}

/** The row of places the word is built in: up to 44px each on a phone, narrower for a long word. */
function Places({
    letters,
    places,
    big,
    label,
}: {
    letters: (string | null)[];
    places: number;
    big: boolean;
    label: string;
}) {
    return (
        <ol
            aria-label={label}
            className={cn(
                'grid w-full justify-center room-motion:animate-room-enter',
                big ? 'gap-[1vh]' : 'gap-1.5',
            )}
            style={{
                gridTemplateColumns: big
                    ? `repeat(${places}, minmax(0, min(8vh, 5.5vw)))`
                    : `repeat(${places}, minmax(0, 2.75rem))`,
            }}
        >
            {Array.from({ length: places }, (_, index) => {
                const letter = letters[index] ?? null;
                const next = letter === null && index === letters.length;

                return (
                    <li
                        key={index}
                        className={cn(
                            'flex items-center justify-center font-semibold select-none',
                            big
                                ? 'h-[10vh] rounded-[1.2vh] text-[min(6vh,4vw)]'
                                : cn('h-12 rounded-lg', slotText(places)),
                            letter === null
                                ? cn(
                                      'border border-dashed',
                                      next
                                          ? 'border-2 border-primary motion-safe:animate-room-breathe'
                                          : 'border-muted-foreground/40',
                                  )
                                : 'border bg-card shadow-xs',
                        )}
                    >
                        {letter !== null && (
                            <span
                                key={`${index}-${letter}`}
                                className="motion-safe:animate-room-pop"
                            >
                                {letter}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/**
 * The places, then the tiles in the server's order. On the screen the
 * same, larger, with the places empty: the room sees how long the word
 * is and its letters, never anyone's taps.
 */
export function AnagramAsk({
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
            <div className="flex w-full flex-col items-center gap-[5vh]">
                <Places
                    letters={[]}
                    places={ask.pick}
                    big
                    label={t('rooms.game.word')}
                />
                <ul
                    className="flex flex-wrap justify-center gap-[1.6vh]"
                    style={tilesWidth(ask.items.length, true)}
                >
                    {ask.items.map((item, index) => (
                        <li
                            key={item.id}
                            style={{ animationDelay: `${stagger(index)}ms` }}
                            className="flex size-[min(13vh,9vw)] items-center justify-center rounded-[min(2.5vh,1.8vw)] border bg-card text-[min(8vh,5.5vw)] font-semibold shadow-sm room-motion:animate-room-pop"
                        >
                            {item.text ?? ''}
                        </li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div className="flex w-full flex-col items-center gap-5">
            <Places
                letters={lettersOf(ask, picked)}
                places={ask.pick}
                big={false}
                label={t('rooms.game.yours')}
            />

            <div
                className="flex w-full flex-wrap justify-center gap-2"
                style={tilesWidth(ask.items.length, false)}
            >
                {ask.items.map((item, index) => {
                    const used = picked.includes(item.id);

                    return (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled || used}
                            aria-pressed={used}
                            aria-label={item.text ?? ''}
                            onClick={(event) => {
                                press(event.currentTarget);
                                onPick(item.id);
                            }}
                            style={{ animationDelay: `${stagger(index)}ms` }}
                            className={cn(
                                'flex size-15 items-center justify-center rounded-2xl border text-3xl font-semibold transition-[opacity,transform] outline-none select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 room-motion:animate-room-pop',
                                used
                                    ? 'border-dashed border-muted-foreground/40 bg-transparent'
                                    : 'bg-card shadow-sm',
                                disabled && !used && 'opacity-60',
                            )}
                        >
                            {/* A moved letter leaves its gap: it now stands in the word above. */}
                            <span className={cn(used && 'invisible')}>
                                {item.text ?? ''}
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/** A word in coloured cells, read by screen readers as one word, with its mark under it. */
function Word({
    kind,
    letters,
    tone,
    big,
    label,
    mark,
    after,
}: {
    /** For checks (`data-word`): the round's word or the viewer's. */
    kind: 'word' | 'yours';
    letters: string[];
    tone: 'right' | 'wrong';
    big: boolean;
    label: string;
    mark: ReactNode;
    /** In a reveal the page saw begin: when its first letter comes in. */
    after: number;
}) {
    return (
        <div
            data-word={kind}
            data-tone={tone}
            className={cn(
                'flex w-full flex-col items-center gap-1.5',
                big && '@container',
            )}
        >
            <span
                className={cn(
                    'text-sm text-muted-foreground',
                    big && 'text-[2.6vh]',
                )}
            >
                {label}
            </span>
            <span className="sr-only">{letters.join('')}</span>
            <span
                aria-hidden
                className={cn(
                    'grid w-full justify-center',
                    big ? 'gap-[1vh]' : 'gap-1.5',
                )}
                style={{
                    gridTemplateColumns: big
                        ? `repeat(${letters.length}, minmax(0, 11vh))`
                        : `repeat(${letters.length}, minmax(0, 2.75rem))`,
                    // On the screen the cells share the reveal's column; a letter fills about 0.6 of its cell.
                    fontSize: big
                        ? `min(7vh, calc(100cqw / ${Math.max(1, letters.length)} * 0.6))`
                        : undefined,
                }}
            >
                {letters.map((letter, index) => (
                    <span
                        key={index}
                        style={{
                            animationDelay: `${after + stagger(index, 60, 480)}ms`,
                        }}
                        className={cn(
                            'flex items-center justify-center border font-semibold room-motion:animate-room-pop',
                            big
                                ? 'h-[12vh] rounded-[1.4vh] border-[0.3vh]'
                                : cn(
                                      'h-12 rounded-lg',
                                      slotText(letters.length),
                                  ),
                            tone === 'right'
                                ? 'border-emerald-600 bg-emerald-600/10 dark:border-emerald-400'
                                : 'border-red-600 bg-destructive/10 dark:border-red-400',
                        )}
                    >
                        {letter}
                    </span>
                ))}
            </span>
            {mark}
        </div>
    );
}

/**
 * The round's word in green cells (from `reveal.solution`, as the tiles
 * write it), and the viewer's own word by the server's verdict: the same
 * word marked «Düzgün · sizin»; another word the letters spell, green
 * under it, «Sizin · bu söz də düzgündür»; a wrong one red, «Sizin».
 */
export function AnagramSolution({
    ask,
    solution,
    mine,
    word,
    big = false,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
    word?: string | null;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const theirs = anagramMine(ask, solution, mine);
    const right = lettersOf(ask, solution);
    const letters = right.some((letter) => letter !== '')
        ? right
        : word
          ? [word]
          : [];

    return (
        <div
            className={cn(
                'flex flex-col items-center',
                big ? 'gap-[4vh]' : 'gap-4',
            )}
        >
            <Word
                kind="word"
                letters={letters}
                tone="right"
                big={big}
                label={t('rooms.game.word')}
                after={REVEAL_MS.solution}
                mark={
                    theirs === null ? null : (
                        <Mark
                            mark={{
                                right: true,
                                yours: theirs === 'same',
                                tone: 'right',
                            }}
                            big={big}
                            keepLine={false}
                        />
                    )
                }
            />
            {mine !== null && (theirs === 'other' || theirs === 'wrong') && (
                <Word
                    kind="yours"
                    letters={lettersOf(ask, mine.picked)}
                    tone={theirs === 'other' ? 'right' : 'wrong'}
                    big={big}
                    label={t('rooms.game.yours')}
                    after={REVEAL_MS.highlight}
                    mark={
                        <Mark
                            mark={{
                                right: false,
                                yours: true,
                                tone: theirs === 'other' ? 'right' : 'wrong',
                            }}
                            big={big}
                            keepLine={false}
                            words={
                                theirs === 'other'
                                    ? t('rooms.game.mark_also_right')
                                    : undefined
                            }
                        />
                    }
                />
            )}
        </div>
    );
}
