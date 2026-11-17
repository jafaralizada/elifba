import { Head } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/rooms';

/**
 * «Otaqlar», the player area's home (architecture §12 "The area and the
 * switch", ADR 019). Plain for now: the next step of B2 opens and joins
 * rooms here, and B4 designs the page.
 */
export default function RoomsIndex() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('rooms.index.title')} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-2 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('rooms.index.title')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('rooms.index.description')}
                </p>
            </div>
        </>
    );
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
