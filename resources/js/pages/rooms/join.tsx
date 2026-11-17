import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { entrance, index } from '@/routes/rooms';
import { store } from '@/routes/rooms/join';

type Props = {
    /** The code of an open room; null at `/otaq`, where it is typed. */
    code: string | null;
    /** The account's own name, as the room will show it. */
    name: string;
};

/**
 * The room's entrance (architecture §12 "Rooms and joining", ADR 020
 * decision 4): the code field at `/otaq`; at `/otaq/{code}` the code, the
 * account's own name as the room will show it, and «Qoşul». A locked or a
 * full room is said here and nothing else about it. Plain until B4.
 */
export default function RoomsJoin({ code, name }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('rooms.join.title')} />

            <div className="mx-auto flex w-full max-w-md flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('rooms.join.title')}
                </h1>

                {code === null ? (
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
                                    placeholder="482 913"
                                    autoFocus
                                    aria-invalid={errors.code !== undefined}
                                    className="h-16 text-center text-3xl font-bold tracking-[0.3em] tabular-nums"
                                />
                                <InputError message={errors.code} />
                                <Button type="submit" disabled={processing}>
                                    {t('rooms.join.next')}
                                </Button>
                            </>
                        )}
                    </Form>
                ) : (
                    <Form {...store.form(code)} className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <p className="text-center text-4xl font-bold tracking-[0.3em] tabular-nums">
                                    {code}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('rooms.join.as')}{' '}
                                    <span className="font-semibold text-foreground">
                                        {name}
                                    </span>
                                </p>
                                <InputError message={errors.code} />
                                <Button
                                    type="submit"
                                    className="h-14 text-base"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('rooms.join.submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

RoomsJoin.layout = {
    breadcrumbs: [
        { title: 'nav.rooms', href: index() },
        { title: 'rooms.join.title', href: entrance() },
    ],
};
