import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { connectOptions, loadVoiceLibrary } from './voice';

/*
 * The real `livekit-client`, loaded the way the page loads it
 * (`loadVoiceLibrary()`, the one `import()` of the library; `RoomsApartTest`):
 * ADR 031 decisions 1 and 2, "As built (C5, audit)". `voice.test.ts` checks
 * what the page asks of the library against a fake; this checks that the
 * library audited is the one installed, and that with the page's options it
 * never takes the voice server's ICE servers — at a join, at a resume or a
 * full reconnect (the same engine), or when the server asks for a relay.
 */

/** What a voice server without `rtc.stun_servers` hands every join. */
const serversList = {
    iceServers: [
        {
            urls: ['stun:global.stun.twilio.com:3478'],
            username: '',
            credential: '',
        },
        {
            urls: ['stun:stun.l.google.com:19302'],
            username: '',
            credential: '',
        },
    ],
};

type Engine = {
    rtcConfig: RTCConfiguration;
    makeRTCConfiguration: (joinResponse?: unknown) => RTCConfiguration;
};

const version = (path: string): unknown =>
    JSON.parse(readFileSync(path, 'utf8')) as {
        version?: unknown;
        dependencies?: Record<string, unknown>;
    };

describe('the voice library', () => {
    it('is the version audited, pinned exactly', () => {
        const manifest = version('package.json') as {
            dependencies: Record<string, unknown>;
        };
        const installed = version(
            'node_modules/livekit-client/package.json',
        ) as { version: unknown };

        expect(manifest.dependencies['livekit-client']).toBe('2.24.1');
        expect(installed.version).toBe('2.24.1');
    });

    it("keeps the page's empty ICE list over the voice server's", async () => {
        const { Room } = await loadVoiceLibrary();
        const engine = (new Room() as unknown as { engine: Engine }).engine;
        engine.rtcConfig = connectOptions().rtcConfig ?? {};

        for (const joinResponse of [
            undefined,
            serversList,
            { ...serversList, clientConfiguration: { forceRelay: 1 } },
        ]) {
            expect(
                engine.makeRTCConfiguration(joinResponse).iceServers,
            ).toEqual([]);
        }

        // Without the page's list the library would take the server's.
        const bare = (new Room() as unknown as { engine: Engine }).engine;
        expect(
            bare
                .makeRTCConfiguration(serversList)
                .iceServers?.map((server) => server.urls),
        ).toEqual([
            ['stun:global.stun.twilio.com:3478'],
            ['stun:stun.l.google.com:19302'],
        ]);
    });
});
