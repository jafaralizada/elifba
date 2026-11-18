import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, DoorOpen, Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import { spacedCode } from '@/components/rooms/room-code';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { entrance, index, show, store } from '@/routes/rooms';
import type { RoomMechanic } from '@/rooms/contract';

type OpenRoom = {
    id: string;
    code: string | null;
    host: boolean;
    players: number;
};
/** A past room: its day, its games and the account's own row of its standing (null for a host who did not play). */
type PastRoom = {
    id: string;
    date: string;
    host: boolean;
    players: number;
    games: { mechanic: RoomMechanic; difficulty: number }[];
    mine: { points: number; place: string | null } | null;
};

type Props = { open: OpenRoom[]; past: PastRoom[] };

/**
 * «Otaqlar», the player area's home (docs/frontend.md rule 15; architecture
 * §12 "Rooms and joining", ADR 020): the account's open rooms first, then
 * the code field, «Otaq aç» (not while it hosts an open room), and the
 * past rooms with their games and the account's own place, labelled on
 * the server.
 */
export default function RoomsIndex({ open, past }: Props) {
    const { t } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const hosts = open.some((room) => room.host);

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
                                    {t('rooms.index.open_room')}
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

                {!hosts && (
                    <section
                        aria-labelledby="open-heading"
                        className="flex flex-col gap-3 rounded-2xl border bg-card p-4"
                    >
                        <h2 id="open-heading" className="font-semibold">
                            {t('rooms.index.open')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.index.open_line')}
                        </p>
                        <Form
                            {...store.form()}
                            transform={(data) => ({
                                plays: data.plays === '1',
                            })}
                            className="flex flex-col gap-3"
                        >
                            {({ processing }) => (
                                <>
                                    <Label
                                        htmlFor="plays"
                                        className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg font-normal"
                                    >
                                        <Checkbox
                                            id="plays"
                                            name="plays"
                                            value="1"
                                            defaultChecked
                                            className="size-5"
                                        />
                                        {t('rooms.index.plays')}
                                    </Label>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="h-12 text-base"
                                        disabled={processing}
                                    >
                                        {processing ? <Spinner /> : <Plus />}
                                        {t('rooms.index.open')}
                                    </Button>
                                    <InputError message={errors.room} />
                                </>
                            )}
                        </Form>
                    </section>
                )}

                <section
                    aria-labelledby="past-heading"
                    className="flex flex-col gap-2"
                >
                    <h2 id="past-heading" className="font-semibold">
                        {t('rooms.index.past_rooms')}
                    </h2>
                    {past.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {open.length === 0
                                ? t('rooms.index.empty')
                                : t('rooms.index.no_past')}
                        </p>
                    )}
                    <ul className="flex flex-col gap-2">
                        {past.map((room) => (
                            <li key={room.id}>
                                <Link
                                    href={show(room.id)}
                                    className="flex min-h-14 items-center gap-3 rounded-xl border bg-card px-4 py-3 transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <span className="flex min-w-0 flex-1 flex-col gap-1.5">
                                        <span className="font-semibold">
                                            {room.date}
                                        </span>
                                        {room.games.length > 0 && (
                                            <span className="flex flex-wrap gap-1">
                                                {room.games.map(
                                                    (game, position) => (
                                                        <span
                                                            key={position}
                                                            className="rounded-md bg-muted px-1.5 py-0.5 text-xs"
                                                        >
                                                            {t(
                                                                'rooms.index.game',
                                                                {
                                                                    game: t(
                                                                        `rooms.mechanics.${game.mechanic}.name`,
                                                                    ),
                                                                    difficulty:
                                                                        t(
                                                                            `rooms.difficulty.${game.difficulty}`,
                                                                        ),
                                                                },
                                                            )}
                                                        </span>
                                                    ),
                                                )}
                                            </span>
                                        )}
                                        <span className="text-sm text-muted-foreground">
                                            {pastLine(room, t)}
                                        </span>
                                    </span>
                                    <ChevronRight
                                        className="size-5 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

/** A past room's muted line: the players, «Siz açmısınız», the account's own place and points. */
function pastLine(
    room: PastRoom,
    t: ReturnType<typeof useTranslation>['t'],
): string {
    const parts = [t('rooms.index.players', { count: room.players })];

    if (room.host) {
        parts.push(t('rooms.index.hosted'));
    }

    if (room.mine !== null && room.games.length > 0) {
        parts.push(
            room.mine.place === null
                ? t('rooms.index.no_points')
                : t('rooms.index.place_points', {
                      place: room.mine.place,
                      points: t('rooms.game.points', {
                          count: room.mine.points,
                      }),
                  }),
        );
    }

    return parts.join(' · ');
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
