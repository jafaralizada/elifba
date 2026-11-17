import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { entrance, index, show, store } from '@/routes/rooms';

type OpenRoom = {
    id: string;
    code: string | null;
    host: boolean;
    players: number;
};
type PastRoom = { id: string; date: string; host: boolean; players: number };

type Props = { open: OpenRoom[]; past: PastRoom[] };

/**
 * «Otaqlar», the player area's home (architecture §12 "Rooms and joining",
 * ADR 020): «Otaq aç», the code field and the account's rooms — open ones
 * it hosts or is in, and past ones. Plain until B4 designs it.
 */
export default function RoomsIndex({ open, past }: Props) {
    const { t } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <>
            <Head title={t('rooms.index.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-8 p-4 md:p-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('rooms.index.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('rooms.index.description')}
                    </p>
                </header>

                <Form
                    {...store.form()}
                    transform={(data) => ({ plays: data.plays === '1' })}
                    className="flex flex-col gap-3"
                >
                    {({ processing }) => (
                        <>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="plays"
                                    value="1"
                                    defaultChecked
                                    className="size-4"
                                />
                                {t('rooms.index.plays')}
                            </label>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('rooms.index.open')}
                            </Button>
                            <InputError message={errors.room} />
                        </>
                    )}
                </Form>

                <Form {...entrance.form()} className="flex flex-col gap-3">
                    {({ errors: codeErrors, processing }) => (
                        <>
                            <Label htmlFor="code">{t('rooms.join.code')}</Label>
                            <Input
                                id="code"
                                name="code"
                                inputMode="numeric"
                                autoComplete="off"
                                placeholder="482 913"
                                aria-invalid={codeErrors.code !== undefined}
                                className="h-14 text-center text-2xl font-bold tracking-[0.3em] tabular-nums"
                            />
                            <InputError
                                message={codeErrors.code ?? errors.code}
                            />
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={processing}
                            >
                                {t('rooms.join.next')}
                            </Button>
                        </>
                    )}
                </Form>

                {open.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('rooms.index.open_rooms')}
                        </h2>
                        {open.map((room) => (
                            <RoomLink key={room.id} id={room.id}>
                                <span className="block font-semibold tabular-nums">
                                    {room.code}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {t('rooms.index.players', {
                                        count: room.players,
                                    })}
                                    {room.host &&
                                        ` · ${t('rooms.index.hosted')}`}
                                </span>
                            </RoomLink>
                        ))}
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <h2 className="font-semibold">
                        {t('rooms.index.past_rooms')}
                    </h2>
                    {past.length === 0 && open.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.index.empty')}
                        </p>
                    )}
                    {past.map((room) => (
                        <RoomLink key={room.id} id={room.id}>
                            <span className="block font-semibold">
                                {room.date}
                            </span>
                            <span className="block text-sm text-muted-foreground">
                                {t('rooms.index.players', {
                                    count: room.players,
                                })}
                                {room.host && ` · ${t('rooms.index.hosted')}`}
                            </span>
                        </RoomLink>
                    ))}
                </section>
            </div>
        </>
    );
}

function RoomLink({ id, children }: { id: string; children: React.ReactNode }) {
    return (
        <Link
            href={show(id)}
            className="flex min-h-14 items-center gap-3 rounded-xl border bg-card px-4 py-3 transition-colors hover:bg-accent"
        >
            <span className="min-w-0 flex-1">{children}</span>
            <ChevronRight
                className="size-5 shrink-0 text-muted-foreground"
                aria-hidden
            />
        </Link>
    );
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
