import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, DoorOpen } from 'lucide-react';
import InputError from '@/components/input-error';
import type { GameSetupOptions } from '@/components/rooms/game-setup';
import { spacedCode } from '@/components/rooms/room-code';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { entrance, index, past, show, store } from '@/routes/rooms';
import type { RoomMechanic } from '@/rooms/contract';

/** An open room the account hosts or is in: the game of its choice and whether a game is running. */
type OpenRoom = {
    id: string;
    code: string | null;
    host: boolean;
    players: number;
    mechanic: RoomMechanic;
    running: boolean;
};

type Props = {
    /** The games a room plays, with what each level holds (`RoomGameSetupData::options()`). */
    catalog: GameSetupOptions;
    open: OpenRoom[];
    /** The code field's address, `{APP_URL}/oyun`: for G3's scanner. */
    entrance: string;
};

/**
 * «Oyunlar», the player area's home (docs/frontend.md rule 15; architecture
 * §12 "Games first", ADR 026): the account's open rooms first, the code
 * field, then the games — choosing one opens a room behind it, or gives it
 * to the host's open room — and the way to the past games. G2 keeps it
 * plain; G3 designs the grid, the code field with its camera and the words.
 */
export default function RoomsIndex({ catalog, open }: Props) {
    const { t } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const families = [...new Set(catalog.mechanics.map((one) => one.family))];

    return (
        <>
            <Head title={t('rooms.index.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 pt-4 pb-10 md:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('rooms.index.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('rooms.index.description')}
                    </p>
                </header>

                {open.map((room) => (
                    <section
                        key={room.id}
                        aria-label={t('rooms.index.open_room')}
                        className="flex flex-col gap-3 rounded-2xl border bg-card p-4"
                    >
                        <div className="flex items-center gap-3">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                <DoorOpen aria-hidden className="size-5" />
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="text-sm text-muted-foreground">
                                    {t(`rooms.mechanics.${room.mechanic}.name`)}
                                </span>
                                {room.code !== null && (
                                    <span className="text-2xl leading-tight font-bold tracking-wider tabular-nums">
                                        {spacedCode(room.code)}
                                    </span>
                                )}
                            </span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.index.players', { count: room.players })}
                            {room.host && ` · ${t('rooms.index.hosted')}`}
                        </p>
                        <Button asChild className="h-13 text-base">
                            <Link href={show(room.id)}>
                                {t('rooms.index.go')}
                                <ChevronRight />
                            </Link>
                        </Button>
                    </section>
                ))}

                <section
                    aria-labelledby="join-heading"
                    className="flex flex-col gap-3 rounded-2xl border bg-card p-4"
                >
                    <h2 id="join-heading" className="font-semibold">
                        {t('rooms.join.title')}
                    </h2>
                    <Form
                        {...entrance.form()}
                        options={{ preserveState: 'errors' }}
                        className="flex flex-col gap-3"
                    >
                        {({ errors: codeErrors, processing }) => (
                            <>
                                <Label htmlFor="code" className="sr-only">
                                    {t('rooms.join.code')}
                                </Label>
                                <Input
                                    id="code"
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    maxLength={9}
                                    placeholder="482 913"
                                    aria-invalid={codeErrors.code !== undefined}
                                    className="h-14 text-center text-2xl font-bold tracking-[0.25em] tabular-nums placeholder:font-normal placeholder:tracking-[0.25em]"
                                />
                                <InputError
                                    message={codeErrors.code ?? errors.code}
                                />
                                <Button
                                    type="submit"
                                    variant={
                                        open.length > 0 ? 'outline' : 'default'
                                    }
                                    className="h-12 text-base"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('rooms.join.next')}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section
                    aria-labelledby="games-heading"
                    className="flex flex-col gap-3"
                >
                    <h2 id="games-heading" className="font-semibold">
                        {t('rooms.setup.title')}
                    </h2>
                    {families.map((family) => (
                        <div key={family} className="flex flex-col gap-2">
                            <h3 className="text-sm text-muted-foreground">
                                {t(`rooms.setup.families.${family}`)}
                            </h3>
                            {catalog.mechanics
                                .filter((one) => one.family === family)
                                .map((one) => (
                                    <Form key={one.mechanic} {...store.form()}>
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="mechanic"
                                                    value={one.mechanic}
                                                />
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="flex min-h-14 w-full items-center gap-3 rounded-xl border bg-card px-4 py-3 text-left transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                >
                                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
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
                                                    {processing ? (
                                                        <Spinner />
                                                    ) : (
                                                        <ChevronRight
                                                            className="size-5 shrink-0 text-muted-foreground"
                                                            aria-hidden
                                                        />
                                                    )}
                                                </button>
                                            </>
                                        )}
                                    </Form>
                                ))}
                        </div>
                    ))}
                    <InputError message={errors.mechanic} />
                </section>

                <Button asChild variant="outline" className="h-12 text-base">
                    <Link href={past()}>
                        {t('rooms.index.past_rooms')}
                        <ChevronRight />
                    </Link>
                </Button>
            </div>
        </>
    );
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
