import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store as startGame } from '@/routes/rooms/games';
import { roomRequest } from '@/rooms/http';

export type GameSetupOptions = {
    mechanics: { mechanic: string; difficulties: number[] }[];
    rounds: { min: number; max: number };
};

/**
 * The host's choice of a new game (docs/architecture.md §12 "The race" →
 * "Starting a game"): a mechanic, a difficulty and the rounds, as the
 * server offers them (`setup` in the page's props). Starting answers 204;
 * every device, the host's too, then learns the game from the state.
 * Plain; B4 designs the setup.
 */
export function GameSetup({
    roomId,
    options,
    onStarted,
}: {
    roomId: string;
    options: GameSetupOptions;
    onStarted: () => void;
}) {
    const { t } = useTranslation();
    const first = options.mechanics[0];
    const [mechanic, setMechanic] = useState(first?.mechanic ?? '');
    const [difficulty, setDifficulty] = useState(first?.difficulties[0] ?? 1);
    const [rounds, setRounds] = useState(String(options.rounds.min));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const difficulties =
        options.mechanics.find((option) => option.mechanic === mechanic)
            ?.difficulties ?? [];

    if (first === undefined) {
        return null;
    }

    const submit = async () => {
        setProcessing(true);
        setErrors({});

        try {
            const reply = await roomRequest('POST', startGame.url(roomId), {
                mechanic,
                difficulty,
                rounds: Number(rounds),
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
        <form
            className="flex flex-col gap-4 rounded-xl border bg-card p-4"
            onSubmit={(event) => {
                event.preventDefault();
                void submit();
            }}
        >
            <h2 className="font-semibold">{t('rooms.setup.title')}</h2>

            <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-sm font-medium">
                    {t('rooms.setup.mechanic')}
                </legend>
                {options.mechanics.map((option) => (
                    <label
                        key={option.mechanic}
                        className="flex items-start gap-2 text-sm"
                    >
                        <input
                            type="radio"
                            name="mechanic"
                            value={option.mechanic}
                            checked={mechanic === option.mechanic}
                            onChange={() => {
                                setMechanic(option.mechanic);
                                setDifficulty(option.difficulties[0] ?? 1);
                            }}
                            className="mt-1 size-4"
                        />
                        <span className="flex flex-col">
                            <span className="font-medium">
                                {t(`rooms.mechanics.${option.mechanic}.name`)}
                            </span>
                            <span className="text-muted-foreground">
                                {t(`rooms.mechanics.${option.mechanic}.line`)}
                            </span>
                        </span>
                    </label>
                ))}
                <InputError message={errors.mechanic} />
            </fieldset>

            <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-sm font-medium">
                    {t('rooms.setup.difficulty')}
                </legend>
                <div className="flex flex-wrap gap-2">
                    {difficulties.map((level) => (
                        <label
                            key={level}
                            className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                        >
                            <input
                                type="radio"
                                name="difficulty"
                                value={level}
                                checked={difficulty === level}
                                onChange={() => setDifficulty(level)}
                                className="size-4"
                            />
                            {t(`rooms.difficulty.${level}`)}
                        </label>
                    ))}
                </div>
                <InputError message={errors.difficulty} />
            </fieldset>

            <div className="flex flex-col gap-2">
                <Label htmlFor="rounds">{t('rooms.setup.rounds')}</Label>
                <Input
                    id="rounds"
                    name="rounds"
                    type="number"
                    inputMode="numeric"
                    min={options.rounds.min}
                    max={options.rounds.max}
                    value={rounds}
                    onChange={(event) => setRounds(event.target.value)}
                    className="w-28"
                />
                <InputError message={errors.rounds} />
            </div>

            <InputError message={errors.form} />
            <Button type="submit" disabled={processing}>
                {processing && <Spinner />}
                {t('rooms.setup.start')}
            </Button>
        </form>
    );
}
