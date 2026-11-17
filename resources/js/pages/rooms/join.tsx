import { Form, Head, Link } from '@inertiajs/react';
import { LogIn, UserRound } from 'lucide-react';
import InputError from '@/components/input-error';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { spacedCode } from '@/components/rooms/room-code';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editProfile } from '@/routes/profile';
import { entrance, index } from '@/routes/rooms';
import { store } from '@/routes/rooms/join';

type Props = {
    /** The code of an open room; null at `/otaq`, where it is typed. */
    code: string | null;
    /** The account's own name, as the room will show it. */
    name: string;
};

/**
 * The room's entrance (docs/frontend.md rule 15, "The entrance";
 * architecture §12 "Rooms and joining", ADR 020 decision 4): the code
 * field at `/otaq`; at `/otaq/{code}` the code, the account's own name as
 * the room will show it, and «Qoşul» at the bottom of the screen. A
 * locked or a full room is said here and nothing else about it.
 */
export default function RoomsJoin({ code, name }: Props) {
    const { t } = useTranslation();

    if (code === null) {
        return (
            <>
                <Head title={t('rooms.join.title')} />
                <div className="mx-auto flex w-full max-w-md flex-col gap-5 px-4 pt-4 pb-10 md:px-6">
                    <header className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('rooms.join.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.join.line')}
                        </p>
                    </header>
                    <Form {...entrance.form()} className="flex flex-col gap-3">
                        {({ errors, processing }) => (
                            <>
                                <Label htmlFor="code">
                                    {t('rooms.join.code')}
                                </Label>
                                <Input
                                    id="code"
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    maxLength={9}
                                    placeholder="482 913"
                                    autoFocus
                                    aria-invalid={errors.code !== undefined}
                                    className="h-16 text-center text-3xl font-bold tracking-[0.25em] tabular-nums placeholder:font-normal"
                                />
                                <InputError message={errors.code} />
                                <Button
                                    type="submit"
                                    className="h-13 text-base"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('rooms.join.next')}
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </>
        );
    }

    return (
        <Form {...store.form(code)} className="flex flex-1 flex-col">
            {({ errors, processing }) => (
                <>
                    <Head title={t('rooms.join.title')} />
                    <div className="mx-auto flex w-full max-w-md flex-col gap-5 px-4 pt-4 pb-6 md:px-6">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('rooms.join.title')}
                        </h1>

                        <section className="flex flex-col items-center gap-1 rounded-2xl border bg-card px-4 py-5">
                            <span className="text-sm text-muted-foreground">
                                {t('rooms.join.code')}
                            </span>
                            <span className="text-5xl font-bold tracking-wider tabular-nums">
                                {spacedCode(code)}
                            </span>
                        </section>

                        <section className="flex flex-col gap-2">
                            <span className="text-sm text-muted-foreground">
                                {t('rooms.join.as')}
                            </span>
                            <span className="flex min-h-14 items-center gap-3 rounded-xl border bg-card py-1 pr-1 pl-3">
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                                    <UserRound aria-hidden className="size-5" />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-lg font-semibold">
                                    {name}
                                </span>
                                <Button
                                    asChild
                                    variant="ghost"
                                    className="h-11 shrink-0 text-muted-foreground"
                                >
                                    <Link href={editProfile()}>
                                        {t('rooms.join.change_name')}
                                    </Link>
                                </Button>
                            </span>
                        </section>

                        <InputError message={errors.code} />
                    </div>

                    <RoomActionBar>
                        <Button
                            type="submit"
                            className="h-14 w-full text-base"
                            disabled={processing}
                        >
                            {processing ? <Spinner /> : <LogIn />}
                            {t('rooms.join.submit')}
                        </Button>
                    </RoomActionBar>
                </>
            )}
        </Form>
    );
}

RoomsJoin.layout = {
    breadcrumbs: [
        { title: 'nav.rooms', href: index() },
        { title: 'rooms.join.title', href: entrance() },
    ],
};
