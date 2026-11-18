import { Link } from '@inertiajs/react';
import { Gauge, Repeat, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import phoneImage from '../../../images/welcome/rooms-phone.webp';
import screenImage from '../../../images/welcome/rooms-screen.webp';
import { Button } from '@/components/ui/button';
import { Picture } from '@/components/welcome/picture';
import { useTranslation } from '@/hooks/use-translation';
import { privacy } from '@/routes';
import { entrance, index as rooms } from '@/routes/rooms';
import type { WelcomeRooms } from '@/types/welcome';

/**
 * «Böyüklər üçün oyunlar» on the welcome page (docs/frontend.md rule 10,
 * architecture §12, ADR 026 decision 4), drawn only while the rooms switch
 * is on: what the adults' games are, their limits, the three families with
 * their games and the way in — «Oyun seç» and «Oyuna qoşul». Every
 * fact is a prop; a game is named by `rooms.mechanics.*`, never drawn with a
 * room's own components, which stay in the player area (`RoomsApartTest`).
 * It promises nothing about memory, the mind or health (`RoomsWordingTest`).
 */
export function RoomsSection({ rooms: facts }: { rooms: WelcomeRooms }) {
    const { t } = useTranslation();
    // A name of two words («Çox çətin») never breaks across lines.
    const difficulties = facts.difficulties
        .map((difficulty) =>
            t(`rooms.difficulty.${difficulty}`).replaceAll(' ', ' '),
        )
        .join(' · ');

    return (
        <section
            aria-labelledby="welcome-rooms"
            className="mx-auto grid w-full max-w-7xl grid-cols-1 gap-x-12 gap-y-10 px-4 pb-24 sm:px-6 lg:grid-cols-12 lg:px-8 lg:pb-32"
        >
            <div className="lg:col-span-4">
                <h2
                    id="welcome-rooms"
                    className="scroll-mt-6 text-3xl font-semibold tracking-tight lg:text-4xl"
                >
                    {t('welcome.rooms.title')}
                </h2>
                <p className="mt-4 max-w-lg text-lg text-pretty text-muted-foreground">
                    {t('welcome.rooms.text')}
                </p>
                <ul role="list" className="mt-6 space-y-3">
                    <Fact icon={<Users />}>
                        {t('welcome.rooms.players', {
                            count: facts.maxPlayers,
                        })}
                    </Fact>
                    <Fact icon={<Repeat />}>
                        {t('welcome.rooms.rounds', facts.rounds)}
                    </Fact>
                    <Fact icon={<Gauge />}>
                        {t('welcome.rooms.difficulties', {
                            list: difficulties,
                        })}
                    </Fact>
                </ul>
            </div>

            <div className="min-w-0 lg:col-span-8">
                <div className="relative mr-3 mb-10 sm:mr-5">
                    <Picture
                        src={screenImage}
                        width={1280}
                        height={720}
                        alt={t('welcome.rooms.screen_alt')}
                        position="object-center"
                    />
                    <img
                        src={phoneImage}
                        width={540}
                        height={566}
                        loading="lazy"
                        decoding="async"
                        alt={t('welcome.rooms.phone_alt')}
                        className="absolute -right-3 -bottom-10 w-[24%] rounded-xl bg-white shadow-xl ring-1 shadow-black/15 ring-black/10 sm:-right-5 dark:ring-white/15"
                    />
                </div>

                <ul
                    role="list"
                    className="mt-14 grid grid-cols-1 gap-x-10 gap-y-10 sm:grid-cols-3"
                >
                    {facts.families.map((family) => (
                        <li
                            key={family.key}
                            className="border-t-2 border-green-700 pt-4 dark:border-green-500"
                        >
                            <h3 className="text-lg font-semibold">
                                {t(`rooms.setup.families.${family.key}`)}
                            </h3>
                            <ul role="list" className="mt-3 space-y-4">
                                {family.mechanics.map((mechanic) => (
                                    <li key={mechanic}>
                                        <p className="font-medium">
                                            {t(
                                                `rooms.mechanics.${mechanic}.name`,
                                            )}
                                        </p>
                                        <p className="mt-1 text-pretty text-muted-foreground">
                                            {t(
                                                `rooms.mechanics.${mechanic}.line`,
                                            )}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>

                <div className="mt-14">
                    <h3 className="text-xl font-semibold">
                        {t('welcome.rooms.join_title')}
                    </h3>
                    <p className="mt-2 max-w-2xl text-pretty text-muted-foreground">
                        {t('welcome.rooms.join_text')}
                    </p>
                    {/* A visitor and an account without «Oyunçu» get there through the login, the registration and the role (architecture §12 "An invited account"). */}
                    <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <Button asChild size="lg" variant="outline">
                            <Link href={rooms()}>
                                {t('welcome.rooms.open')}
                            </Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href={entrance()}>
                                {t('welcome.rooms.enter')}
                            </Link>
                        </Button>
                    </div>
                    <p className="mt-6 max-w-2xl text-pretty text-muted-foreground">
                        {/* The voice's form only while the instance's live voice is on (ADR 031 decision 15). */}
                        {t(
                            facts.voice
                                ? 'welcome.rooms.names_voice'
                                : 'welcome.rooms.names',
                        )}{' '}
                        <Link
                            href={`${privacy().url}#rooms`}
                            className="font-medium text-foreground underline underline-offset-4 hover:decoration-2"
                        >
                            {t('welcome.rooms.privacy')}
                        </Link>
                    </p>
                </div>
            </div>
        </section>
    );
}

/** One limit of a room, beside its icon. */
function Fact({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <li className="flex items-center gap-3 text-lg [&_svg]:size-5 [&_svg]:shrink-0 [&_svg]:text-green-700 dark:[&_svg]:text-green-500">
            <span aria-hidden>{icon}</span>
            {children}
        </li>
    );
}
