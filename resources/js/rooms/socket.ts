/**
 * Echo for a room page (docs/architecture.md §12 "The race"): the
 * account's session only — `/broadcasting/auth` gets the XSRF cookie's
 * token, never a bearer — and one listener, `room.changed`. Only
 * `pages/rooms/show.tsx` imports this module, so no other page of the
 * player area loads pusher-js. The connection is built from `VITE_REVERB_*`
 * alone, as the board's is.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { authenticate } from '@/actions/Illuminate/Broadcasting/BroadcastController';
import { xsrfToken } from './http';
import type { RoomChannel, SocketState } from './live';

export type RoomEcho = Echo<'reverb'>;

export function connectRoomEcho(): RoomEcho {
    const env = import.meta.env;
    const scheme = env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(
        env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80),
    );

    return new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: env.VITE_REVERB_APP_KEY,
        wsHost: env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            transport: 'ajax',
            endpoint: authenticate.url(),
            headersProvider: () => {
                const xsrf = xsrfToken();

                return {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrf === null ? {} : { 'X-XSRF-TOKEN': xsrf }),
                };
            },
        },
    });
}

function socketState(
    status: ReturnType<RoomEcho['connectionStatus']>,
): SocketState {
    switch (status) {
        case 'connected':
            return 'connected';
        case 'connecting':
        case 'reconnecting':
            return 'connecting';
        default:
            return 'disconnected';
    }
}

/** The room's private channel, `private-room.{id}`, for `live.ts`: it only listens. */
export function roomChannel(echo: RoomEcho, roomId: string): RoomChannel {
    const name = `room.${roomId}`;
    const channel = echo.private(name);
    const offs: (() => void)[] = [];

    return {
        onChanged: (listener) => {
            channel.listen('.room.changed', listener);
        },
        onSubscribed: (listener) => {
            channel.subscribed(listener);
        },
        onError: (listener) => {
            channel.error(listener);
        },
        onSocket: (listener) => {
            listener(socketState(echo.connectionStatus()));
            offs.push(
                echo.connector.onConnectionChange((status) =>
                    listener(socketState(status)),
                ),
            );
        },
        close: () => {
            for (const off of offs) {
                off();
            }

            echo.leave(name);
            echo.disconnect();
        },
    };
}
