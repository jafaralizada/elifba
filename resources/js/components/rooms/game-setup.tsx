import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { seconds } from '@/components/rooms/seconds';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store as startGame } from '@/routes/rooms/games';
import type { RoomMechanic } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

/** A level as the setup describes it (`RoomGameSetupData::options()`). */
export type SetupLevel = {
    difficulty: number;
    study_ms: number;
    answer_ms: number;
    /** How many items a round asks to recall: a row's length, the lit cells. */
    count: { min: number; max: number } | null;
    grid: { cols: number; rows: number } | null;
};

export type GameSetupOptions = {
    mechanics: {
        mechanic: RoomMechanic;
        family: string;
        difficulties: number[];
        levels: SetupLevel[];
    }[];
    rounds: { min: number; max: number };
};

/** The host's last choice, kept by the page while it is open. */
export type SetupChoice = {
    mechanic: RoomMechanic;
    difficulty: number;
    rounds: number;
};

/** The round counts offered: every multiple of 5 between the bounds, and the bounds. */
export function roundSteps(bounds: { min: number; max: number }): number[] {
    const steps = new Set([bounds.min, bounds.max]);

    for (
        let step = Math.ceil(bounds.min / 5) * 5;
        step <= bounds.max;
        step += 5
    ) {
        steps.add(step);
    }

    return [...steps].sort((a, b) => a - b);
}

/**
 * The host's choice of a new game (docs/frontend.md rule 15, "The setup";
 * architecture §12 "Starting a game"): «Yeni oyun» opens a dialog, full
 * screen on a phone, with the games grouped by family, the difficulty with
 * what its level holds, and the rounds as steps — all as the server offers
 * them (`setup` in the page's props). Starting answers 204; every device,
 * the host's too, then learns the game from the state.
 */
export function GameSetup({
    roomId,
    options,
    choice,
    onChoice,
    onStarted,
}: {
    roomId: string;
    options: GameSetupOptions;
    choice: SetupChoice | null;
    onChoice: (choice: SetupChoice) => void;
    onStarted: () => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const first = options.mechanics[0];

    if (first === undefined) {
        return null;
    }

    const steps = roundSteps(options.rounds);
    const chosen: SetupChoice = choice ?? {
        mechanic: first.mechanic,
        difficulty: first.difficulties[0] ?? 1,
        rounds: options.rounds.min,
    };
    const offer =
        options.mechanics.find((one) => one.mechanic === chosen.mechanic) ??
        first;
    const level =
        offer.levels.find((one) => one.difficulty === chosen.difficulty) ??
        offer.levels[0];
    const families = [...new Set(options.mechanics.map((one) => one.family))];
    const mark = t('rooms.decimal_mark');

    const choose = (next: Partial<SetupChoice>) =>
        onChoice({ ...chosen, ...next });

    const submit = async () => {
        setProcessing(true);
        setErrors({});

        try {
            const reply = await roomRequest('POST', startGame.url(roomId), {
                mechanic: chosen.mechanic,
                difficulty: chosen.difficulty,
                rounds: chosen.rounds,
            });

            if (reply.status === 422) {
                const body = reply.body as {
                    errors?: Record<string, string[]>;
                } | null;
                setErrors(
                    Object.fromEntries(
                        Object.entries(body?.errors ?? {}).map(
                            ([key, messages]) => [key, messages[0] ?? ''],
                        ),
                    ),
                );
            } else if (reply.status !== 204 && reply.status !== 403) {
                setErrors({ form: t('rooms.setup.failed') });
            } else {
                setOpen(false);
            }

            // Started, or another start won: the state says which.
            onStarted();
        } catch {
            setErrors({ form: t('rooms.setup.failed') });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" className="h-13 w-full text-base">
                    <Plus />
                    {t('rooms.setup.title')}
                </Button>
            </DialogTrigger>
            <DialogContent
                showCloseButton={false}
                className="flex h-dvh max-h-dvh w-full max-w-none flex-col gap-0 overflow-hidden rounded-none p-0 sm:h-auto sm:max-h-[90dvh] sm:max-w-lg sm:rounded-2xl"
            >
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <div className="flex items-start gap-3 border-b px-4 pt-[max(env(safe-area-inset-top),0.5rem)] pb-2">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5 pt-1.5">
                            <DialogTitle className="text-xl">
                                {t('rooms.setup.title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('rooms.setup.description')}
                            </DialogDescription>
                        </div>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="size-11 shrink-0 rounded-full"
                                aria-label={t('common.close')}
                            >
                                <X className="size-5" />
                            </Button>
                        </DialogClose>
                    </div>

                    <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-4 py-3">
                        {families.map((family) => (
                            <fieldset
                                key={family}
                                className="flex flex-col gap-2"
                            >
                                <legend className="mb-1.5 text-sm font-semibold">
                                    {t(`rooms.setup.families.${family}`)}
                                </legend>
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {options.mechanics
                                        .filter((one) => one.family === family)
                                        .map((one) => {
                                            const pressed =
                                                one.mechanic ===
                                                chosen.mechanic;

                                            return (
                                                <button
                                                    key={one.mechanic}
                                                    type="button"
                                                    aria-pressed={pressed}
                                                    onClick={() =>
                                                        choose({
                                                            mechanic:
                                                                one.mechanic,
                                                            difficulty:
                                                                one.difficulties.includes(
                                                                    chosen.difficulty,
                                                                )
                                                                    ? chosen.difficulty
                                                                    : (one
                                                                          .difficulties[0] ??
                                                                      1),
                                                        })
                                                    }
                                                    className={cn(
                                                        'flex min-h-11 items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                                        pressed
                                                            ? 'border-primary bg-accent ring-1 ring-primary'
                                                            : 'hover:bg-accent/60',
                                                    )}
                                                >
                                                    <MechanicPicture
                                                        mechanic={one.mechanic}
                                                    />
                                                    <span className="flex min-w-0 flex-col gap-0.5">
                                                        <span className="font-semibold">
                                                            {t(
                                                                `rooms.mechanics.${one.mechanic}.name`,
                                                            )}
                                                        </span>
                                                        <span className="text-[0.8125rem] leading-snug text-muted-foreground">
                                                            {t(
                                                                `rooms.mechanics.${one.mechanic}.line`,
                                                            )}
                                                        </span>
                                                    </span>
                                                </button>
                                            );
                                        })}
                                </div>
                            </fieldset>
                        ))}
                        <InputError message={errors.mechanic} />

                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1.5 text-sm font-semibold">
                                {t('rooms.setup.difficulty')}
                            </legend>
                            <div className="grid grid-cols-4 gap-1 rounded-xl bg-muted p-1">
                                {offer.difficulties.map((difficulty) => (
                                    <button
                                        key={difficulty}
                                        type="button"
                                        aria-pressed={
                                            difficulty === chosen.difficulty
                                        }
                                        onClick={() => choose({ difficulty })}
                                        className={cn(
                                            'min-h-11 rounded-lg px-1 text-[0.8125rem] leading-tight font-medium transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                            difficulty === chosen.difficulty
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {t(`rooms.difficulty.${difficulty}`)}
                                    </button>
                                ))}
                            </div>
                            {level !== undefined && level.count !== null && (
                                <p className="text-[0.8125rem] leading-snug text-muted-foreground tabular-nums">
                                    {level.grid !== null
                                        ? t('rooms.setup.levels.grid', {
                                              cols: level.grid.cols,
                                              rows: level.grid.rows,
                                              min: level.count.min,
                                              max: level.count.max,
                                              study: seconds(
                                                  level.study_ms,
                                                  mark,
                                              ),
                                              answer: seconds(
                                                  level.answer_ms,
                                                  mark,
                                              ),
                                          })
                                        : t('rooms.setup.levels.row', {
                                              min: level.count.min,
                                              max: level.count.max,
                                              study: seconds(
                                                  level.study_ms,
                                                  mark,
                                              ),
                                              answer: seconds(
                                                  level.answer_ms,
                                                  mark,
                                              ),
                                          })}
                                </p>
                            )}
                            <InputError message={errors.difficulty} />
                        </fieldset>

                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1.5 text-sm font-semibold">
                                {t('rooms.setup.rounds')}
                            </legend>
                            <div
                                className="grid gap-2"
                                style={{
                                    gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))`,
                                }}
                            >
                                {steps.map((step) => (
                                    <Button
                                        key={step}
                                        type="button"
                                        variant={
                                            step === chosen.rounds
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={step === chosen.rounds}
                                        className="h-11 text-base tabular-nums"
                                        onClick={() => choose({ rounds: step })}
                                    >
                                        {step}
                                    </Button>
                                ))}
                            </div>
                            <InputError message={errors.rounds} />
                        </fieldset>
                    </div>

                    <div className="flex flex-col gap-2 border-t px-4 pt-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                        <InputError message={errors.form} />
                        <p className="truncate text-center text-sm text-muted-foreground">
                            {t('rooms.setup.summary', {
                                game: t(
                                    `rooms.mechanics.${chosen.mechanic}.name`,
                                ),
                                difficulty: t(
                                    `rooms.difficulty.${chosen.difficulty}`,
                                ),
                                count: chosen.rounds,
                            })}
                        </p>
                        <Button
                            type="submit"
                            className="h-13 w-full text-base"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {t('rooms.setup.start')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A small drawing of a game's round, in the tokens: a row of shapes, a grid with lit cells. */
function MechanicPicture({ mechanic }: { mechanic: RoomMechanic }) {
    if (mechanic === 'recall_cells') {
        const lit = new Set([0, 4, 5, 7]);

        return (
            <span
                aria-hidden
                className="grid size-14 shrink-0 grid-cols-3 gap-1 rounded-xl bg-muted p-1.5"
            >
                {Array.from({ length: 9 }, (_, index) => (
                    <span
                        key={index}
                        className={cn(
                            'rounded-[3px]',
                            lit.has(index) ? 'bg-amber-400' : 'bg-background',
                        )}
                    />
                ))}
            </span>
        );
    }

    return (
        <span
            aria-hidden
            className="flex size-14 shrink-0 items-center justify-center gap-1 rounded-xl bg-muted p-1.5"
        >
            <span className="size-3 rounded-full bg-primary" />
            <span className="size-3 rounded-[2px] bg-primary/70" />
            <span className="size-0 border-x-[7px] border-b-[12px] border-x-transparent border-b-primary/45" />
        </span>
    );
}
