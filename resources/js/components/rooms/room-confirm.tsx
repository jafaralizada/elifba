import { Form } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    /** The button that opens the dialog. */
    trigger: ReactNode;
    title: string;
    description: string;
    confirmLabel: string;
    /** Red confirm button for what cannot be undone. */
    destructive?: boolean;
} & (
    | {
          /** The Wayfinder form the confirm button submits (an Inertia visit). */
          form: RouteFormDefinition<'get' | 'post'>;
          onConfirm?: never;
      }
    | {
          /** For a route that answers 204 to `fetch` (ending a game). */
          onConfirm: () => Promise<unknown>;
          form?: never;
      }
);

/**
 * Asks before a room action someone may regret (docs/frontend.md rule 15,
 * "Asking first"): closing the room, ending a game, removing a player,
 * deleting a room, taking one's name out. The shared `ConfirmDialog`'s
 * look with 44px buttons, for a form or for a `fetch` call. Texts come
 * translated.
 */
export function RoomConfirm({
    trigger,
    title,
    description,
    confirmLabel,
    destructive = false,
    form,
    onConfirm,
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const footer = (processing: boolean, submit?: () => void) => (
        <DialogFooter className="gap-2">
            <DialogClose asChild>
                <Button type="button" variant="secondary" className="h-11">
                    {t('common.cancel')}
                </Button>
            </DialogClose>
            <Button
                type={submit === undefined ? 'submit' : 'button'}
                variant={destructive ? 'destructive' : 'default'}
                className="h-11"
                disabled={processing}
                onClick={submit}
            >
                {processing && <Spinner />}
                {confirmLabel}
            </Button>
        </DialogFooter>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                {form !== undefined ? (
                    <Form
                        {...form}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing }) => footer(processing)}
                    </Form>
                ) : (
                    footer(busy, () => {
                        setBusy(true);
                        void onConfirm()
                            .catch(() => null)
                            .finally(() => {
                                setBusy(false);
                                setOpen(false);
                            });
                    })
                )}
            </DialogContent>
        </Dialog>
    );
}
