import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Smartphone } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { playersLabel } from '@/lib/game-players';
import { pult } from '@/routes';
import { remote } from '@/routes/games';

type LiveGame = {
    id: string;
    status: 'live' | 'paused';
    mechanic: string;
    /** A class's game, or a parent's child's home game (exactly one). */
    schoolClass: { id: string; name: string } | null;
    child: { id: string; display_name: string } | null;
};

type Props = { games: LiveGame[] };

/**
 * `/pult` on the teacher's phone (docs/architecture.md §4): type the code
 * the board shows, or open one of the teacher's live games. With exactly
 * one live game the server opens its remote at once; `?list=1` (the
 * remote's back link) stays here. A code that opens nothing, or one tried
 * too often, is refused under the field (docs/frontend.md rule 5).
 */
export default function PultIndex({ games }: Props) {
    const { t } = useTranslation();
    // A QR that opens nothing arrives here by a full page load: its refusal
    // is in the page's errors, not in the form's own.
    const { errors: arrived } = usePage<{ errors: Record<string, string> }>()
        .props;

    return (
        <>
            <Head title={t('pult.title')} />

            <main className="mx-auto flex min-h-svh w-full max-w-md flex-col gap-8 bg-background p-4 text-foreground">
                <header className="flex items-center gap-3 pt-4">
                    <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground">
                        <Smartphone className="size-6" aria-hidden />
                    </span>
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">
                            {t('pult.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('pult.intro')}
                        </p>
                    </div>
                </header>

                <Form
                    {...pult.form()}
                    options={{ preserveState: 'errors' }}
                    className="flex flex-col gap-3"
                >
                    {({ errors, processing }) => {
                        const refusal = errors.code ?? arrived.code;

                        return (
                            <>
                                <Label htmlFor="code">{t('pult.code')}</Label>
                                <Input
                                    id="code"
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    placeholder="482 913"
                                    aria-invalid={refusal !== undefined}
                                    className="h-16 text-center text-3xl font-bold tracking-[0.3em] tabular-nums"
                                />
                                <InputError message={refusal} />
                                <Button
                                    type="submit"
                                    className="h-14 text-base"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('pult.open')}
                                </Button>
                            </>
                        );
                    }}
                </Form>

                <section className="flex flex-col gap-3">
                    <h2 className="font-semibold">{t('pult.games')}</h2>
                    {games.length === 0 && (
                        <p className="text-muted-foreground">
                            {t('pult.no_games')}
                        </p>
                    )}
                    {games.map((game) => (
                        <Link
                            key={game.id}
                            href={remote(game.id)}
                            className="flex min-h-16 min-w-0 items-center gap-3 rounded-2xl border-2 bg-card px-4 py-3 transition-colors hover:bg-accent"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold">
                                    {t(
                                        playersLabel(game).key,
                                        playersLabel(game).values,
                                    )}
                                </span>
                                <span className="block truncate text-sm text-muted-foreground">
                                    {t(`games.mechanics.${game.mechanic}`)}
                                    {' · '}
                                    {t(`pult.status.${game.status}`)}
                                </span>
                            </span>
                            <ChevronRight
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                        </Link>
                    ))}
                </section>
            </main>
        </>
    );
}
