import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { LinkedText } from '@/components/app/linked-text';
import AuthDivider from '@/components/auth-divider';
import GoogleSignIn from '@/components/google-sign-in';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import RememberMe from '@/components/remember-me';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { login, privacy } from '@/routes';
import { redirect as google } from '@/routes/google';
import { store } from '@/routes/rooms/invited';

type Props = {
    passwordRules: string;
    /** Whether this instance offers Google sign-in (ADR 008). */
    googleSignIn: boolean;
};

/**
 * The invitation's door (docs/frontend.md rule 15 "The invitation link",
 * architecture §12 "The invitation link (I2)", ADR 033 decisions 11–13):
 * where a visitor who opened a game's link by hand makes an account with
 * the player role in the same step. Nothing of the room — no code, game,
 * host or players — and the same page for any code. Google first where
 * the instance offers it, then the short form: the name (as the game will
 * show it), the address, one password, «Məni xatırla», and two ticks never
 * merged or ticked in advance — the privacy note's and the player's
 * sentence. «Daxil olun» goes to the login with the link kept.
 */
export default function RoomsInvited({ passwordRules, googleSignIn }: Props) {
    const { t } = useTranslation();
    const [remember, setRemember] = useState(true);

    return (
        <>
            <Head title={t('rooms.invited.head')} />

            <div className="flex flex-col gap-6">
                {/* Under the card's heading, as the layout draws a description; its link keeps the game. */}
                <p className="-mt-6 text-center text-sm text-balance text-muted-foreground">
                    <LinkedText
                        text={t('rooms.invited.line')}
                        href={login().url}
                    />
                </p>

                {googleSignIn && (
                    <>
                        <GoogleSignIn
                            href={google.url({
                                query: { remember: remember ? 1 : 0 },
                            })}
                            label={t('rooms.invited.google')}
                        />
                        <AuthDivider label={t('auth.google.or_email')} />
                    </>
                )}

                <Form
                    {...store.form()}
                    resetOnSuccess={['password']}
                    disableWhileProcessing
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('auth.fields.name')}
                                </Label>
                                <Input
                                    id="name"
                                    className="h-10"
                                    type="text"
                                    required
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t(
                                        'auth.fields.name_placeholder',
                                    )}
                                    aria-describedby="name-line"
                                />
                                <p
                                    id="name-line"
                                    className="text-[0.8125rem] text-muted-foreground"
                                >
                                    {t('rooms.invited.name_line')}
                                </p>
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    className="h-10"
                                    type="email"
                                    required
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('common.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    className="h-10"
                                    required
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={t('common.password')}
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <RememberMe
                                checked={remember}
                                onCheckedChange={setRemember}
                            />

                            <div className="grid gap-2">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="terms"
                                        name="terms"
                                        value="1"
                                        required
                                        className="mt-0.5"
                                        aria-invalid={
                                            errors.terms ? true : undefined
                                        }
                                    />
                                    <Label
                                        htmlFor="terms"
                                        className="leading-snug font-normal"
                                    >
                                        <span>
                                            <LinkedText
                                                text={t('auth.register.terms')}
                                                href={privacy().url}
                                                target="_blank"
                                                rel="noopener"
                                            />
                                        </span>
                                    </Label>
                                </div>
                                <InputError message={errors.terms} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="agreement"
                                        name="agreement"
                                        value="1"
                                        required
                                        className="mt-0.5"
                                        aria-invalid={
                                            errors.agreement ? true : undefined
                                        }
                                    />
                                    <Label
                                        htmlFor="agreement"
                                        className="leading-snug font-normal"
                                    >
                                        {t('roles.agreement.player')}
                                    </Label>
                                </div>
                                <InputError message={errors.agreement} />
                            </div>

                            <Button
                                type="submit"
                                className="mt-1 h-11 w-full"
                                data-test="invited-button"
                            >
                                {processing && <Spinner />}
                                {t('rooms.invited.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

RoomsInvited.layout = {
    title: 'rooms.invited.title',
};
