// Components
import { Form, Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import AuthStatus from '@/components/auth-status';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The confirmation notice (architecture §7). It looks again whenever it is
 * shown again, so an address confirmed in another tab or browser goes on
 * by itself; with `game` — a game's link opened by hand waits behind the
 * confirmation (§12 "The invitation link (I2)", ADR 033 decision 14) — it
 * says that the mail's link leads into the game.
 */
export default function VerifyEmail({
    status,
    game = false,
}: {
    status?: string;
    game?: boolean;
}) {
    const { t } = useTranslation();

    useEffect(() => {
        const look = () => {
            if (document.visibilityState === 'visible') {
                router.reload();
            }
        };

        document.addEventListener('visibilitychange', look);

        return () => document.removeEventListener('visibilitychange', look);
    }, []);

    return (
        <>
            <Head title={t('auth.verify.title')} />

            {game && (
                <p className="-mt-4 mb-6 text-center text-sm font-medium text-balance">
                    {t('rooms.invited.verify_line')}
                </p>
            )}

            {status === 'verification-link-sent' && (
                <div className="mb-6">
                    <AuthStatus message={t('auth.verify.link_sent')} />
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {t('auth.verify.resend')}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            {t('nav.log_out')}
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'auth.verify.title',
    description: 'auth.verify.description',
};
