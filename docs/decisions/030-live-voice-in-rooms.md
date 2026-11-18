# 030 — Live voice in a room: our own voice server, the host's switch, each player's own microphone

- **Status:** Proposed, 2026-11-18 (roadmap "Live voice", step C1: design only, no code). The owner decided on 2026-11-18 that players talk through a voice server we host, that voice is off in a room until the host turns it on, that each player opens their own microphone, that the host can mute anyone and that voice is never recorded. Three clickable prototypes were opened for the owner the same day (`~/elifba-shots/voice/prototypes/`). **The variant (A, «Zolaq»), the newcomer rule (decision 12) and the sentences of decisions 14–17 are this step's recommendation, taken under the owner's standing instruction of that day not to wait for an answer; the owner has not yet read them.** They are a public promise and a stamped consent, so the owner (or a lawyer) reads them before `ELIFBA_ROOMS_VOICE` is turned on anywhere (C5-O); changing a sentence is lines in `lang/`, changing the newcomer rule is decision 12 and one column. Built by C2 (the voice server in Docker), C3 (the server and the contract), C4 (the pages) and C5 (hardening and audit).
- **Sections:** architecture.md §10 "Hosting" ("Live voice"), §12 "Live voice", "Adults' privacy", "Guard tests", "What each step builds" ("Live voice (C1–C5)"); docs/frontend.md rules 9 and 15 ("Live voice"); glossary.md "Live voice"
- **Amends:**
    - [024](024-adults-privacy-in-rooms.md) decision 1: the account gains a second fact, `users.voice_agreed_at`. Decision 2: a room row gains `voice_at` and `voice_epoch`, a player's row `voice_admitted_at` and `voice_muted_at`; still nothing a person typed, and nothing a person said. Decision 6 ("No contact between people") holds as written on an instance without voice; with voice on, players of one room can talk in that room, live, and in no other way — still no chat, message, reaction or invitation. Decision 10: `/privacy` gains the part «Canlı səs» and three sentences get a voice form (decisions 15–17). Decision 11 ("No third party") holds and is the reason for decisions 1–4. Its rejected "Chat or reactions: the one feature that would need moderation and an age rule" stays rejected; voice is answered by decisions 9–13, not by an age. "What would make us revisit" stands and gains the lines below.
    - [021](021-room-race-protocol.md) decision 6: the state gains `voice` and becomes `v: 3`. Decision 8 holds word for word for the room channel: it still carries `room.changed` `{v, revision}` and nothing else, and a room page still sends and hears no client event on it. Voice is a second connection, to the voice server (decision 5).
    - [025](025-room-motion-sound-and-final.md) decision 11 ("The screen is the room's speaker"): it is the speaker of the room's **cues**; voices are heard on each player's own phone, and the screen plays, captures and joins no voice. Decision 12 holds: a phone still plays only its own quiet cues, now beside the voice. Decision 14's "No voice" meant a spoken prompt and still does: no prerecorded or synthesised speech in a room.
    - [020](020-rooms-and-joining.md) decision 9: three actions join the list. [026](026-games-first.md): unchanged; the host turns voice on from «Otağın idarəsi».
    - docs/frontend.md rule 15 "Nothing more": "No voice" gives way to rule 15 "Live voice". Rule 9: a room page with voice opens a second connection, to the instance's own host.
- **Why it is hard to reverse:** it is the first time Elifba lets people reach each other, and the first published ports beside the proxy's. A voice heard cannot be taken back; a sentence accepted is stamped; a port opened on a firewall and a server reachable from the internet stay a duty for as long as the instance runs.

## Context

Rooms were built so that nobody could reach anybody: a name among people who share a code, and nothing else ([024](024-adults-privacy-in-rooms.md)). The owner asked on 2026-11-18 that players be able to talk while they play — friends in different homes on their phones — "at a fully professional level", and decided the frame: a server of our own (no cloud, no phone to phone), off until the host turns it on, each player's own microphone, the host mutes anyone, never recorded.

What this meets:

- **No age is asked** (024 decision 7): a 15–18 year old may be in a room, and an account that guesses a six-digit code joins it (020). Until now such a stranger was a name in a list. With voice, a stranger could listen to a private conversation and speak into it, so "seen in the lobby and removed" is no longer enough by itself.
- **No third-party request** (024 decision 11, frontend rule 9). The usual way to add voice — a cloud service, or WebRTC with a public STUN server — is one.
- **The race** (021): the server alone keeps the clock, and nothing is queued. A voice server that is slow or down must never move a round.
- **One host** (§10): Caddy holds the only published ports, and the firewall allows 22, 80 and 443.
- **Nothing recorded** means Elifba can never review what was said. The only moderator is the host, in the room, at that moment. That is the price of the promise, and it is stated on `/privacy`.

**What was checked, and how** (2026-11-18; source read at pinned tags, nothing was run — C2 runs it):

| Checked                    | Found                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| The server                 | `livekit/livekit` **v1.13.11** (2026-10-25), Apache-2.0; image `livekit/livekit-server:v1.13.11` (digest `sha256:25cd902d…1e3d20`, amd64 and arm64). An SFU: it forwards Opus packets and decodes nothing; the speaker is found from the RTP audio-level header. Nothing on disk, no database; Redis only for several nodes.                                                                                                                                       |
| The page's library         | `livekit-client` **2.24.1** (npm, Apache-2.0; its dependencies Apache-2.0, BSD-3-Clause, MIT and 0BSD, all bundled into its `dist/`), about 150 KB gzipped. No hard-coded ICE server, no telemetry, nothing fetched from elsewhere; the region lookup runs only for `*.livekit.cloud` hosts.                                                                                                                                                                       |
| **The STUN trap**          | Real, three times. (a) `rtc.use_external_ip: true` — what LiveKit's own guide and generator set — makes the server ask `global.stun.twilio.com` and `stun.l.google.com` for its address. (b) So does an unset `node_ip`, `--dev` included. (c) Every join response hands the browser those three hosts unless `rtc.stun_servers` or a TURN server is configured; an empty list means the default list (`pkg/service/roommanager.go` `iceServersForParticipant()`). |
| Tickets                    | A JWT, HS256, signed with the API secret; `sub` is the identity, which every participant of the room sees; `name` is optional. A permission left out is **granted**. `exp` bounds only the join: the server sends a connected client a refreshed token every 5 minutes that lives 10. A token with `roomCreate` creates a room even when `room.auto_create` is false.                                                                                              |
| Removal                    | `RemoveParticipant` does not invalidate a token, and the self-hosted server has no revocation: a changed client can rejoin for up to ten minutes. Deleting the room disconnects everyone, and a room that does not exist cannot be joined while `auto_create` is false.                                                                                                                                                                                            |
| Muting                     | Revoking `can_publish` (`UpdateParticipant`) unpublishes the track at once; the call replaces every permission, so the whole set is sent. The server never unmutes a microphone (`room.enable_remote_unmute` is false by default).                                                                                                                                                                                                                                 |
| What the operator can hear | Media is encrypted hop by hop (DTLS-SRTP) and the SFU holds the keys. Whoever holds the API secret can also join any room as a `hidden` listener. End-to-end encryption exists, but the key would come from the same server.                                                                                                                                                                                                                                       |
| Ports                      | Signalling: one WebSocket on 7880 (`/rtc`, `/rtc/v1`, `/rtc/validate`), with the ticket in the query (`access_token`). Media: one UDP port (`rtc.udp_port`, 7882) and ICE over TCP on 7881, neither of which can pass through a proxy. TURN over TLS on 443 beside a website needs a layer-4 proxy with SNI (`caddy-l4`, a custom Caddy build that calls itself experimental) and a second hostname.                                                               |
| Its log                    | At `info`: the voice room's name, the identity, moments, and public addresses with their last three characters cut. `name` and `metadata` are marked personal — and are never sent (decision 6).                                                                                                                                                                                                                                                                   |
| Capacity                   | Published: about 75 000 forwarded audio packets a second per core. One room of 20 with 3 talking is about 3 700: a twentieth of a core.                                                                                                                                                                                                                                                                                                                            |

## Decision

### The voice server and what leaves a phone

1. **Our own voice server, and only it.** A self-hosted LiveKit server, pinned to `v1.13.11`, is one more service of the instance (`voice`, decision 27). A phone's audio goes to it and from it to the other phones of that room: never to a cloud service, and never from phone to phone, so no player learns another's IP address. The library in the page is `livekit-client`, pinned to an exact version in `package.json`, bundled by Vite as its own chunk and imported only by `resources/js/rooms/voice.ts`, only when a player taps «Səsə qoşul». No CDN, no worker or model fetched from elsewhere, no Krisp.
2. **No public STUN server, on either side.** Three settings, each with its proof:
    - the voice server is told its address — `rtc.node_ip` from `ELIFBA_ROOMS_VOICE_IP`, `rtc.use_external_ip: false` — so it asks nobody (C2: the stack starts healthy with no route out and logs the explicit address);
    - `rtc.stun_servers` names only the instance's own address, since an empty list would mean Twilio's and Google's (C2: the config check of `scripts/docker-smoke.sh`);
    - the page never uses the server's list: it always connects with `rtcConfig: { iceServers: [] }` (C4: Vitest `voice.test.ts` "connects with no ICE server, its own or the server's"). A phone needs none: the voice server has a public address and answers on it.

    C5 proves all three at once: in a browser driven over the DevTools protocol every `RTCPeerConnection` was built with an empty list and every request went to the app's own host, and a packet capture on the host shows no packet to UDP 3478 or 19302 and no lookup of a STUN name while two phones join.

3. **The voice server calls nobody.** No webhook (`webhook.urls` unset), no telemetry, no tracing, no Redis, no egress, ingress, SIP or agent service. It answers the browser's WebSocket and the app's API calls, and sends media to the phones that joined.
4. **Transport: UDP first, TCP as the fallback, no TURN in the first release.** A phone sends media to the instance's address on UDP 7882 and, where a network blocks UDP, over TCP on 7881. TURN over TLS on 443 is not built: it would replace the pinned official Caddy image — the only door to the whole app — with a custom build carrying an experimental layer-4 plugin, and need a second hostname. A network that allows nothing but 80 and 443 therefore gets no voice; the page says so and the game plays on (decision 26). C5 counts how often that happens.
5. **Two connections, two jobs.** The room channel is untouched: `room.changed` `{v: 1, revision}` and nothing else, no client event. A page in the voice holds a second connection, to the voice server, which carries audio and the voice server's own signalling — who is connected, whose microphone is open, who is speaking. It carries no game fact, the page never sends a data message (`canPublishData: false`), and nothing of a round reads it: not the clock, not `RoomWaits`, not a point. What the voice connection says is drawn and forgotten.

### Who may be in the voice

6. **A voice participant is `room_players.id` and nothing else.** That ULID is the ticket's `sub`. A ticket has no `name`, `metadata` or `attributes`; the voice server never learns a name, an e-mail address or an account id, and a page names a speaker from the state's `players` by that id, as it names everyone.
7. **The stored facts** (migration `add_voice_to_rooms`, additive, nullable; the columns exist whether or not the switch is on):

    | Column                           | Is                                                                                         |
    | -------------------------------- | ------------------------------------------------------------------------------------------ |
    | `rooms.voice_at`                 | the host turned the room's voice on at this moment; null: off, as every room starts        |
    | `rooms.voice_epoch`              | a small counter (default 0) in the voice room's name, raised to close a voice room for all |
    | `room_players.voice_admitted_at` | this player was let into the voice; null: waiting (decision 12)                            |
    | `room_players.voice_muted_at`    | the host muted this player; null: may speak                                                |
    | `users.voice_agreed_at`          | the account accepted the voice's sentence (decision 14)                                    |

    Nothing else is stored: not who joined the voice or when, not whose microphone was open, not who spoke or for how long. Those are the voice server's, in memory, while they last.

8. **A player's standing is derived** (`Rooms\Support\RoomVoice::standingOf()`, `Rooms\Enums\RoomVoiceStanding`): `waiting` (not admitted: no ticket), `muted` (admitted and muted: listens), `open` (admitted: listens and may open the microphone). The host's own row is always `open`, and nobody can mute or hold the host.
9. **The host's three tools, and only the host's** (`RoomPolicy::voice`: the host of an open room, while the instance's voice is on):
    - `PUT /rooms/{room}/voice` `{on}` → `SetRoomVoice`. Turning it **on** sets `voice_at`, admits every player who is present at that moment (they are the list the host is looking at) and the host. Turning it **off** clears `voice_at` and raises the epoch. Allowed at any moment, a round included. Refused with `rooms.voice.errors.busy` while `elifba.rooms.voice.max_rooms` (5) other rooms have their voice on.
    - `PUT /rooms/{room}/members/{member}/voice` `{standing: open | muted}` → `SetRoomPlayerVoice`: admits a waiting player (as `open` or as `muted`), mutes, or lets a muted player speak again. Never the host's own row. A mute stays until the host lifts it, through the voice going off and on.
    - `RemoveRoomPlayer`, as it is, which now also raises the epoch while the voice is on.

    Each runs under the room row's lock, raises the revision and signals, like the lock and the choice. They are guarded by the policy and by nothing about the page; the chosen variant draws them on the host's phone only.

10. **Letting a muted player speak never opens a microphone.** It gives the permission back; the player opens the microphone again themselves. Nothing — not the host, not the server, not a reconnect the player did not ask for — turns on a microphone its owner turned off, and `room.enable_remote_unmute` stays false.
11. **Joining the voice is each player's own act, every time.** While the voice is on, an admitted player sees «Səsə qoşul» and may never tap it: the game is the same without it. A join starts with the microphone off. Leaving («Səsdən çıx») is one tap and changes nothing in the game.
12. **The newcomer waits.** An account that joins the room while its voice is on gets a player's row without `voice_admitted_at`: it plays like everyone, sees that the voice is on, and **hears nothing and can say nothing until the host taps «Səsə burax»** — no ticket is issued for it (decision 18). The host sees «:name səsə buraxılmağı gözləyir» with that button. A player who had left and returns keeps what their row had. So a stranger who guessed a code is a name in a list for the host to judge, as before voice existed.
13. **No age, still.** Nothing asks one. What stands between a young account holder and a stranger's voice is the code (a stranger has to guess it), the wait (decision 12), the host's mute and removal, the voice being off in every room until a host turns it on, and that nothing can be played back later. If that proves too little, the remedy is the switch, not a field (see "What would make us revisit").

### Consent and notice

14. **One sentence, accepted once per account, stamped.** Before an account's first join the page shows the sentence and three lines of notice; «Razıyam, qoşul» sends `agree: true` with the ticket request and `JoinRoomVoice` stamps `users.voice_agreed_at`. Without it no ticket is issued (422, `rooms.voice.errors.agreement`). Listening needs it too: a listener is in the conversation and is shown as being there. The microphone then has the browser's own question, asked the first time the player opens it and never before. The player role's sentence (`roles.agreement.player`) is unchanged, word for word.

    |     | `rooms.voice.agreement`                                                                                                      |
    | --- | ---------------------------------------------------------------------------------------------------------------------------- |
    | az  | Canlı səsə qoşuluram: mikrofonumu açanda səsimi birlikdə oynadığım oyunçular eşidir. Səs yazılmır.                           |
    | en  | I am joining the live voice: when I turn my microphone on, the players I am playing with hear me. The voice is not recorded. |

    The three lines under it (notice, not consent): `rooms.voice.note.mic` «Mikrofonunuz sönülü başlayır; onu yalnız özünüz açırsınız.» / "Your microphone starts off; only you turn it on."; `rooms.voice.note.host` «Aparıcı istənilən oyunçunu susdura və canlı səsi söndürə bilər.» / "The host can mute any player and turn the live voice off."; `rooms.voice.note.near` «Yan-yana oturanlar qulaqlıq taxsın və ya yalnız bir telefon qoşulsun — yoxsa səs əks-səda verir.» / "People sitting side by side should wear headphones or let only one phone join — otherwise the voice echoes."

15. **The welcome page.** `welcome.rooms.names` keeps its text and is shown while the instance's voice is off. While it is on the page shows `welcome.rooms.names_voice`:

    |     | `welcome.rooms.names_voice`                                                                                                                                                                                                            |
    | --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | az  | Oyunçular bir-birinin yalnız hesabdakı adını görür. Yaş soruşulmur, çat və mesaj yoxdur. Aparıcı açsa, oyunçular oyun zamanı canlı səslə danışa bilər: mikrofonu hər kəs özü açır, səs yazılmır.                                       |
    | en  | Players see only each other's account name. No age is asked, and there is no chat or messaging. If the host turns it on, players can talk live while they play: everyone turns their own microphone on, and the voice is not recorded. |

16. **`/privacy`, the three sentences.** `privacy.rooms.contact`, `.not_stored` and `.where` keep their texts and are shown while the voice is off — they stay true there. While it is on the page shows their voice forms:

    | Key                              | az                                                                                                                                                                                                                                                                                                                                                                                                                                    | en                                                                                                                                                                                                                                                                                                                                                                                                                                              |
    | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | `privacy.rooms.contact_voice`    | Elifba heç kimin yaşını soruşmur və oyunçuların bir-birinə yazması üçün heç bir yol yoxdur: mesaj, çat, reaksiya və ya dəvət göndərilmir. Oyunçular bir-biri ilə yalnız aparıcının açdığı canlı səsdə danışa bilər (aşağıda). Otağın kodunu insanlar bir-birinə Elifba-dan kənarda verir. Aparıcı istənilən oyunçunu otaqdan çıxara bilər; çıxarılan oyunçu o otağa qayıda bilmir, adı orada görünmür və canlı səsdən dərhal kəsilir. | Elifba asks no one's age, and there is no way for players to write to each other: no message, chat, reaction or invitation is sent. Players can talk to each other only in the live voice the host turns on (below). People pass a room's code to each other outside Elifba. The host can remove any player; a removed player cannot come back to that room, their name is no longer shown in it, and they are cut from the live voice at once. |
    | `privacy.rooms.not_stored_voice` | Yaş, doğum tarixi, cins, telefon, şəkil və ya ləqəb soruşulmur və saxlanılmır; otaq üçün cihaz və ya yer məlumatı da saxlanılmır. Otağın adı və çatı yoxdur, cavab yazılmır, toxunaraq seçilir — buna görə otaqda heç kimin yazdığı mətn saxlanılmır. Canlı səs də saxlanılmır: nə yazısı, nə mətni, nə də kimin nə vaxt danışdığı.                                                                                                   | No age, date of birth, gender, phone number, photo or nickname is asked for or stored, and nothing about a device or a location is stored for a room. A room has no name and no chat, and answers are tapped, never typed, so nothing anyone writes is stored for a room. The live voice is not stored either: no recording, no transcript, and not who spoke or when.                                                                          |
    | `privacy.rooms.where_voice`      | Otaqların məlumatı da bu nüsxənin öz serverində saxlanılır və heç kimə verilmir. Canlı səs də yalnız bu nüsxənin öz səs serverindən keçir. Otaq səhifələri kənar xidmətlərə sorğu göndərmir.                                                                                                                                                                                                                                          | Room data is kept on this instance's own server too, and is given to no one. The live voice also passes only through this instance's own voice server. Room pages make no requests to outside services.                                                                                                                                                                                                                                         |

17. **`/privacy`, the new part** — `privacy.rooms.voice_title` «Canlı səs» / "Live voice", and `privacy.rooms.voice`, five paragraphs, shown only while the voice is on:

    **az**

    > Canlı səs hər otaqda sönülü olur; onu yalnız aparıcı aça və istənilən an söndürə bilər. Səsə hər oyunçu özü qoşulur və qoşulmaya da bilər: oyun səssiz də eyni cür oynanır. İlk dəfə qoşulanda razılıq soruşulur və verildiyi vaxt hesabda saxlanılır.
    >
    > Mikrofon həmişə sönülü başlayır və onu yalnız oyunçu özü açır; brauzer əvvəlcə icazə soruşur. Mikrofon açıq olanda səsinizi həmin otağın səsə qoşulmuş oyunçuları və aparıcısı canlı eşidir — başqa heç kim. Aparıcı istənilən oyunçunu susdura bilər. Səs açılandan sonra otağa gələn oyunçu aparıcı onu səsə buraxana qədər nə eşidir, nə danışır.
    >
    > Səs yazılmır, mətnə çevrilmir və saxlanılmır: səs serveri onu yalnız həmin an digər oyunçulara ötürür. Böyük ekran səsi nə səsləndirir, nə də dinləyir. Deyilənləri sonradan heç kim, o cümlədən Elifba, dinləyə bilməz; buna görə otaqda nizamı yalnız aparıcı qoruyur.
    >
    > Səs telefondan bu nüsxənin öz səs serverinə şifrələnmiş yolla gedir və oradan digər oyunçulara ötürülür. Kənar xidmətdən keçmir və telefonlar bir-birinə birbaşa bağlanmır, buna görə oyunçular bir-birinin IP ünvanını görmür. Uçdan-uca şifrələmə yoxdur: serveri idarə edən, hər bir zəng serverində olduğu kimi, texniki cəhətdən canlı səsə çıxış əldə edə bilər.
    >
    > Canlı səs üçün saxlanılan yalnız budur: hesabda razılığın verildiyi vaxt; otaqda səsin açıq olub-olmadığı, aparıcının kimi səsə buraxdığı və kimi susdurduğu. Səs serveri bağlantı üçün IP ünvanınızı görür; onun jurnalına yalnız texniki qeydlər düşür — bağlantının vaxtı, otağın və oyunçunun təsadüfi nömrəsi, IP ünvanının bir hissəsi — ad və səs yox. Jurnal məhdud ölçüdədir: yeni qeydlər köhnələrini silir.

    **en**

    > The live voice is off in every room; only the host can turn it on, and can turn it off at any moment. Each player joins the voice themselves and may choose not to: the game plays the same without it. The first time you join you are asked to agree, and the moment you agreed is kept with your account.
    >
    > The microphone always starts off and only the player turns it on; the browser asks for permission first. While your microphone is on, the players of that room who joined the voice, and its host, hear you live — and nobody else. The host can mute any player. A player who comes into the room after the voice was turned on hears nothing and can say nothing until the host lets them into the voice.
    >
    > The voice is not recorded, not turned into text and not kept: the voice server only passes it on to the other players at that moment. The big screen neither plays the voice nor listens. Nobody, Elifba included, can listen later to what was said; that is why only the host keeps order in a room.
    >
    > The voice travels encrypted from the phone to this instance's own voice server and from there to the other players. It passes through no outside service, and the phones do not connect to each other directly, so players do not see each other's IP address. There is no end-to-end encryption: whoever runs the server could technically get access to the live voice, as with any call server.
    >
    > This is all that is kept for the live voice: with the account, the moment it agreed; with the room, whether its voice is on, and whom the host let into the voice and whom the host muted. The voice server sees your IP address in order to connect; its log holds only technical lines — the moment of a connection, the random number of the room and of the player, a part of the IP address — and no name and no voice. The log has a fixed size: new lines push out old ones.

    `privacy.rooms.stored`, `.who`, `.why`, `.how_long` and `.delete` are unchanged. `PrivacyController` and `WelcomeController` add `voice: true` to their `rooms` prop only while `RoomsArea::voice()`, and the pages choose the form from it.

### The ticket

18. **Who gets one** (`RoomPolicy::listen`): the host of an open room whose voice is on — whether or not the host plays, since whoever can mute must be able to hear — and a present player of that room who is admitted. Nobody else: not a waiting newcomer, a player who left, a removed player, an account outside the room, a closed or idle room, a room whose voice is off, a guest board's bearer. **The screen gets none**: `rooms/screen` has no voice code and asks for nothing (decision 22).
19. **`POST /rooms/{room}/voice/tickets`** `{agree?: true}` → `Rooms\Actions\JoinRoomVoice` (`throttle:room-voice`, 12 a minute per account). It stamps the agreement when asked to, makes sure the voice room exists at the voice server, and answers `{url, token}` (`RoomVoiceTicketData`, JSON, `no-store`) — or 503 `{status: "unavailable"}` when the voice server does not answer within `elifba.rooms.voice.timeout_ms`. A ticket is never logged, never put in a redirect and never broadcast.
20. **What a ticket is** (`Rooms\Support\RoomVoiceTicket`, signed with `firebase/php-jwt`, which C3 names in `composer.json`; no LiveKit SDK): HS256 with the instance's voice secret; `iss` the voice key, `sub` the player's row id, `exp` 60 seconds (`elifba.rooms.voice.ticket_seconds`), and

    ```
    video: { room: "{rooms.id}.{voice_epoch}", roomJoin: true,
             canSubscribe: true, canPublish: <standing is open>, canPublishData: false,
             canPublishSources: ["microphone"], canUpdateOwnMetadata: false }
    ```

    Every permission is written out, since the voice server grants what a token leaves out. It never holds `roomCreate`, `roomAdmin`, `roomList`, `roomRecord`, `hidden`, `recorder`, `agent` or `ingressAdmin`. The secret lives in `.env` (`ELIFBA_ROOMS_VOICE_SECRET`, written by `scripts/docker-env.sh`), is read through `config('elifba.rooms.voice.secret')` and never reaches a page.

21. **The voice room** is named `{rooms.id}.{voice_epoch}`, created only by the app (`room.auto_create: false`; `max_participants` = `elifba.rooms.max_players` + 1, for a host who does not play; emptied rooms close themselves after five minutes and are made again by the next ticket). The app's own calls carry tokens minted per call that live 30 seconds, with only the grant that call needs.

### Enforcement at the voice server

22. **The page is never the enforcement.** `Rooms\Support\RoomVoiceServer` is the one class that talks to the voice server (Laravel's HTTP client, the Twirp JSON calls `CreateRoom`, `DeleteRoom`, `RemoveParticipant`, `UpdateParticipant`, `ListRooms`, `ListParticipants`; connect and answer within `timeout_ms`, 1 500). Each action tells it what changed **after the commit and after the signal**, in the same request, never inside the transaction or under the room's lock, and no queue is involved:

    | What happens                                                     | The voice server is told                                                                                                         | Old tickets                                     |
    | ---------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
    | The host mutes a player                                          | `UpdateParticipant` with the whole permission set and `can_publish: false`: the microphone's track is unpublished at once        | see decision 23                                 |
    | The host lets a muted player speak                               | `UpdateParticipant` with `can_publish: true`; the player opens the microphone themselves                                         | —                                               |
    | The host admits a waiting player                                 | nothing: that player now gets a ticket                                                                                           | —                                               |
    | The host removes a player                                        | the epoch is raised with the removal; `DeleteRoom` of the old name. Everyone is disconnected and the others' pages come back in  | dead: their room no longer exists               |
    | The host turns the voice off; the host closes the room           | the epoch is raised; `DeleteRoom`                                                                                                | dead                                            |
    | A player leaves the room                                         | `RemoveParticipant`                                                                                                              | see decision 23                                 |
    | The host steps out of playing («Mən də oynayıram» off)           | nothing: the host keeps the voice                                                                                                | —                                               |
    | The host locks the room                                          | nothing: a lock admits no new player, and whoever is in stays                                                                    | —                                               |
    | A room goes idle, an account is deleted, anything else           | no request at that moment: tickets are refused at once (`Room::isOpen()`), and the sweep closes the voice room within 15 seconds | dead once swept                                 |
    | The instance's switch goes off (`ELIFBA_ROOMS_VOICE`, a restart) | the routes are gone at once; the sweep, which runs while a voice server is configured, deletes every voice room it finds         | dead; or the operator stops the `voice` service |

    A call that fails or times out never fails the write: it is reported as `Rooms\Exceptions\RoomVoiceNotCut` with the room id and the cause's class — never a name, an identity or a ticket — and the sweep finishes the job.

23. **The sweep** (`Rooms\Actions\SweepRoomVoices`, `rooms:voice-sweep`, every 15 seconds by the scheduler that already runs; registered while a voice server is configured). It lists the voice rooms and their participants and compares them with the facts:
    - a voice room whose room is not open, has its voice off or has another epoch — or with the instance's switch off, any voice room — is deleted;
    - a participant who may not be there (not the host, not a present admitted player), or a muted player who holds the permission to publish, means a ticket from before the fact was replayed or a cut did not arrive. The sweep does not patch it: it raises the epoch and deletes the voice room, so every old ticket dies and each page comes back with a new one.

    It reads and writes no round, no clock and nothing of `RoomWaits`. So a mute costs the room nothing in the honest case, a replayed ticket buys at most 15 seconds, and a removal is final at once.

24. **A voice server that is down delays nothing.** No request to it is made by `RecordRoomAnswer`, `RecordRoomRead`, `RoomState`, `StartRoomGame`, `EndRoomGame` or `ChooseRoomGame`. `LeaveRoom` and `RemoveRoomPlayer` commit their round's close and signal first; only the answer to that one request can wait, for at most `timeout_ms`. With the voice server down there is no voice, so nothing is left to cut; when it comes back it knows no room, and nobody can enter one until the app makes it again.

### Who hears where, and when

25. **Where a voice is heard.**
    - **A phone** plays the voice through the output the phone chooses for media — its loudspeaker, or headphones when they are connected. A page cannot pick the earpiece and does not try; loudness is the phone's volume keys. There is no volume per person.
    - **The big screen never joins**: it asks for no ticket, plays no voice and captures nothing. It shows the state's facts — that the voice is on, who waits, who is muted — and a line saying the voice is on the phones. Voices from a television beside open microphones would come back to everyone as an echo, and a second connection under the host's identity would push the host's phone out.
    - **Players in one physical room.** A phone's echo canceller removes only what that phone plays; it cannot remove another phone's loudspeaker. So people side by side wear headphones or let one phone join — the notice says it (decision 14), microphones start off, and the host can mute. Nothing detects it and nothing is promised.
    - **Cues beside the voice** (ADR 025). The screen plays the room's cues as before. A phone in the voice keeps its own quiet cues at the same level; a cue is never sent into the voice by the page. The cue mute (`elifba.rooms.phone.muted`) does not mute the voice, and leaving the voice does not mute the cues. On iOS an open microphone changes the audio session and Safari may play other audio lower: accepted. C5 checks on a real iPhone that a cue never interrupts the voice; if it does, a phone's cues are silent while its microphone is open, and this decision says so then.
    - **Two words apart.** The cue toggle is renamed so that «Səs» is not two things: `rooms.sound.label` «Oyun səsləri» / "Game sounds", `rooms.sound.unlock` «Oyun səslərini aç» / "Turn game sounds on" (C4).
26. **In every phase, with nothing switched by the game.** The voice is the room's, not a game's: it runs from the start screen through the countdown, the study, the question, the reveal and the final, and between games, until the host turns it off or the room closes. No phase mutes or unmutes anyone. **Saying an answer aloud is allowed** — it is what people round a table can do; each phone's own answer is still what the server judges, and a host who wants a silent race mutes or turns the voice off.

    **Failures**, each said in words with an icon, none stopping the game:

    | What happens                                             | The page                                                                                                                                                                                                                |
    | -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | The browser's permission is refused                      | the player keeps listening; the microphone button says so and where to allow it (`rooms.voice.errors.denied`)                                                                                                           |
    | No microphone                                            | the same, with `rooms.voice.errors.no_mic`                                                                                                                                                                              |
    | An insecure context (plain http on a LAN address)        | the voice is not offered on that page: one line, `rooms.voice.errors.insecure`                                                                                                                                          |
    | The voice server is unreachable; only 80 and 443 allowed | «Canlı səsə qoşulmaq alınmadı. Oyun davam edir.» with «Yenidən cəhd et»; nothing else on the page changes                                                                                                               |
    | The network drops or changes                             | the library resumes by itself; while it tries the bar says «Səs kəsildi, yenidən qoşulur…»; after about 45 seconds it stops and offers «Yenidən qoşul»                                                                  |
    | The voice room is closed under the page (a new epoch)    | the page asks for a new ticket and comes back, with its microphone as the player had it a moment before                                                                                                                 |
    | The page is hidden, or the phone is locked               | the page turns its own microphone off at once — no open microphone on a page nobody sees — and says so when it is shown again; listening goes on where the browser lets it, and the page comes back in when shown again |
    | One account on two devices                               | the newest device has the voice; the older says «Canlı səs başqa cihazınızda açıqdır.» with «Burada qoşul»                                                                                                              |
    | The host's phone is gone                                 | the voice stays as it was; newcomers wait, since only the host admits; an idle room closes by itself                                                                                                                    |

### Hosting

27. **The service.** `voice`: `livekit/livekit-server:v1.13.11`, config `docker/voice/livekit.yaml`, behind the Compose profile `voice` — an instance that does not name the profile starts exactly today's six services. It publishes the first ports beside the proxy's: **`7882/udp`** and **`7881/tcp`**, which the host's firewall must allow. Caddy routes `/rtc` and `/rtc/*` to `voice:7880` — the WebSocket only — and nothing else of the voice server is public: its API is for `app` and `scheduler` on the internal network, as Reverb's is. The ticket travels in that WebSocket's query, so the route is never access-logged. Its log is `info`, in Docker's `json-file` with `max-size: 5m` and `max-file: 3`: a size, not a time, which `/privacy` says as it is.
28. **Configuration** (one `.env`, as now; `scripts/docker-env.sh` writes the key and the secret with `openssl` and leaves the switch off):

    | `.env`                                                | Config                                                    | Is                                                                            |
    | ----------------------------------------------------- | --------------------------------------------------------- | ----------------------------------------------------------------------------- |
    | `ELIFBA_ROOMS_VOICE`                                  | `elifba.rooms.voice.enabled`                              | the switch, false unless set                                                  |
    | `ELIFBA_ROOMS_VOICE_KEY`, `ELIFBA_ROOMS_VOICE_SECRET` | `.key`, `.secret`                                         | the voice server's API key and secret (also its `LIVEKIT_KEYS`)               |
    | `ELIFBA_ROOMS_VOICE_URL`                              | `.url`                                                    | what a browser opens; Compose sets `wss://{domain}:{port}` (`ws` with `http`) |
    | `ELIFBA_ROOMS_VOICE_API_URL`                          | `.api_url`                                                | what the app calls; Compose sets `http://voice:7880`                          |
    | `ELIFBA_ROOMS_VOICE_IP`                               | — (the `voice` service only)                              | the host's public IPv4: `rtc.node_ip` and the one entry of `rtc.stun_servers` |
    | `COMPOSE_PROFILES=voice`                              | —                                                         | starts the service                                                            |
    | —                                                     | `.ticket_seconds` 60, `.timeout_ms` 1 500, `.max_rooms` 5 | fixed in `config/elifba.php`                                                  |

    `App\Support\RoomsArea::voice()` is the one reader of the switch: true only while the area is on, the switch is set, and the key, the secret and both addresses are there.

29. **Development and a real phone.** `composer dev` is unchanged and needs no Docker. `composer dev:voice` (C2) runs the pinned image with `docker/voice/livekit.dev.yaml` — a fixed development key, `node_ip` 127.0.0.1, its own `stun_servers` — never the bare `--dev`, which would ask the public STUN servers. A microphone needs a secure context: on the laptop that is `https://elifba.test` in Chrome, or `http://localhost:8000`. A real phone needs https on the LAN, so C2 gives the Docker try-out an https mode with Caddy's internal certificate authority (the phone trusts it once) and the media straight to the laptop's LAN address. **Real phones are tried on that try-out before the release; the production host is measured only after it**, in the release's own plan.
30. **Capacity.** The voice server adds about a twentieth of a core for a room of 20 with 3 talking, and about 200 MB; `max_rooms` (5) keeps the worst case — every microphone open and noisy — near one core. The instance needs at least 2 vCPU and 4 GB with voice on; the release's plan starts by reading the VPS's size and running `lk load-test` on it.

### Targets C5 measures

31. Numbers, on two real phones and in a headless harness with twenty fake microphones:

    | What                                                     | Target                                                      |
    | -------------------------------------------------------- | ----------------------------------------------------------- |
    | «Səsə qoşul» to hearing the room                         | median ≤ 1.5 s, 95 % ≤ 3 s on a mobile network              |
    | Mouth to ear                                             | median ≤ 250 ms, 95 % ≤ 400 ms                              |
    | A network change (Wi-Fi to mobile)                       | voice back within 5 s without a tap (95 %)                  |
    | The host's mute to silence at the listeners              | ≤ 1 s (95 %)                                                |
    | A removal, the voice off, a close: to the cut            | ≤ 2 s (95 %); 20 s at worst, by the sweep                   |
    | The others' gap after a removal                          | voice back within 3 s (95 %)                                |
    | 20 players and the host connected, 3 talking, 10 minutes | no dropout; per phone ≤ 250 kb/s down and ≤ 80 kb/s up      |
    | 21 microphones open and silent                           | ≤ 100 kb/s down per phone                                   |
    | The `voice` service for that room                        | ≤ 15 % of one core, ≤ 200 MB                                |
    | The game with the voice on                               | a question still shown within 0.5 s of `answer_from_ms`     |
    | The voice server killed in the middle of a game          | no round, clock or answer differs from a game without voice |

    Audio: Opus, mono, the library's `speech` preset (24 kb/s; its default is `music`, 48), DTX and RED on; the browser's own echo cancellation, noise suppression and gain control; `stopMicTrackOnMute: true`, so a closed microphone is a released one and the browser's indicator goes out. Any number of microphones may be open; nothing picks "the top speakers".

### What never happens

32. **No recording, no transcription, no processing of audio on the server**: no egress, ingress, agent or SIP service is deployed, the SFU decodes nothing, and no `hidden` or `recorder` grant is ever minted by the app. No voice message, no playback, no "who spoke most". No name, ticket or voice in a log, an exception, a broadcast or a URL. No video, screen sharing, text chat, reaction, friend or invitation. No text that promises a benefit.
33. **A class game, a home game, a student and a child never get voice.** Every voice route is in the player group behind `rooms.voice`; no `Classroom`, `Family`, `Play` or `Rankings` file, no children's page and no board gains a line; `livekit-client` is imported by one file of `resources/js/rooms/`. The children's "voice" — the board's prerecorded clips (ADR 015, 016) — is another thing and another word («səsləndirmə»), and `elifba.voice.*` stays its key.

### The pages (C4; docs/frontend.md rule 15 "Live voice")

34. **Variant A, «Zolaq».** The voice is a bar docked above the tab bar on every moment of the room page — the start screen, a round, a reveal, the final — and absent while the voice is off:
    - **the microphone** is the bar's button, in words: «Sönülü» with `MicOff`, or «Açıq» with `Mic`, filled, so its owner never overlooks an open microphone; «Susdurulub» with a dashed outline for a muted player;
    - **the speaker** is the bar's text: «:name danışır», «Heç kim danışmır», with «Səsdə: :count nəfər» under it;
    - **joining**: the bar is an invitation — «Aparıcı canlı səsi açıb», «Səsə qoşul» — and the first time the sheet with the sentence; a waiting newcomer's bar says they wait;
    - **the host** turns the voice on with a switch in «Otağın idarəsi», and mutes, admits and turns it off in the sheet «Canlı səs» that the bar opens — one tap away in a round too. A waiting newcomer adds a line above the bar with «Səsə burax»;
    - **the screen** shows a chip «Canlı səs açıqdır» in its top bar, marks a waiting or muted player's chip, and says that the voice is on the phones.

    A state is an icon with a word, never a colour alone; no avatar, photo or nickname; the voice has one colour of its own (indigo), which is neither right, wrong nor the study's lamp. The bar takes 56px of a round: a 4 × 4 grid keeps cells of 67px on a 375 × 667 screen.

## Words

`rooms.voice.*` (C4), in both languages; «otaq» is in none of them (ADR 026 decision 2), and none promises anything.

| Key                                     | az                                                                                                               | en                                                                                                               |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `.title`                                | Canlı səs                                                                                                        | Live voice                                                                                                       |
| `.host_off`                             | Açsanız, oyunçular oyun zamanı bir-biri ilə danışa bilər. Səs yazılmır.                                          | Turn it on and players can talk to each other while they play. It is not recorded.                               |
| `.host_on`                              | Açıqdır. Söndürsəniz, hamı üçün dərhal kəsilir.                                                                  | It is on. Turn it off and it stops for everyone at once.                                                         |
| `.turn_off`                             | Canlı səsi söndür                                                                                                | Turn live voice off                                                                                              |
| `.on`                                   | Canlı səs açıqdır                                                                                                | Live voice is on                                                                                                 |
| `.invite`, `.invite_line`               | Aparıcı canlı səsi açıb · Qoşulmaq sizin seçiminizdir                                                            | The host turned live voice on · Joining is your choice                                                           |
| `.join`, `.leave`                       | Səsə qoşul · Səsdən çıx                                                                                          | Join the voice · Leave the voice                                                                                 |
| `.agree`, `.not_now`                    | Razıyam, qoşul · İndi yox                                                                                        | I agree, join · Not now                                                                                          |
| `.mic.off`, `.mic.on`, `.mic.muted`     | Sönülü · Açıq · Susdurulub                                                                                       | Off · On · Muted                                                                                                 |
| `.mic.open`, `.mic.close`               | Mikrofonu aç · Mikrofonu söndür                                                                                  | Turn microphone on · Turn microphone off                                                                         |
| `.speaks`, `.you_speak`, `.silent`      | :name danışır · Siz danışırsınız · Heç kim danışmır                                                              | :name is speaking · You are speaking · Nobody is speaking                                                        |
| `.speak_two`, `.speak_many`             | :name və :other danışır · :count nəfər danışır                                                                   | :name and :other are speaking · :count people are speaking                                                       |
| `.count`, `.people`                     | Səsdə: :count nəfər · Səsdəkilər                                                                                 | In the voice: :count · In the voice                                                                              |
| `.state.speaking`, `.mic`, `.listening` | danışır · mikrofonu açıq · dinləyir                                                                              | speaking · microphone on · listening                                                                             |
| `.state.away`, `.muted`, `.waiting`     | səsdə deyil · aparıcı susdurub · icazə gözləyir                                                                  | not in the voice · muted by the host · waiting to be let in                                                      |
| `.mute`, `.unmute`, `.admit`            | Susdur · Danışmağa icazə ver · Səsə burax                                                                        | Mute · Let them speak · Let into the voice                                                                       |
| `.muted_you`                            | Aparıcı sizi susdurub. Dinləyə bilərsiniz.                                                                       | The host muted you. You can listen.                                                                              |
| `.waiting_you`                          | Canlı səs açıqdır. Aparıcı sizi səsə buraxanda qoşula biləcəksiniz.                                              | Live voice is on. You can join once the host lets you in.                                                        |
| `.waiting_host`                         | :name səsə buraxılmağı gözləyir                                                                                  | :name is waiting to be let into the voice                                                                        |
| `.turned_off`                           | Aparıcı canlı səsi söndürdü.                                                                                     | The host turned live voice off.                                                                                  |
| `.screen`                               | Böyük ekran səsi səsləndirmir və dinləmir — səs hər kəsin öz telefonundadır.                                     | The big screen neither plays nor hears the voice — it is on each player's phone.                                 |
| `.reconnecting`, `.retry`, `.rejoin`    | Səs kəsildi, yenidən qoşulur… · Yenidən cəhd et · Yenidən qoşul                                                  | The voice dropped, reconnecting… · Try again · Join again                                                        |
| `.elsewhere`, `.here`                   | Canlı səs başqa cihazınızda açıqdır. · Burada qoşul                                                              | Live voice is open on another device of yours. · Join here                                                       |
| `.hidden_mic`                           | Səhifə gizlənəndə mikrofonunuz söndürüldü.                                                                       | Your microphone was turned off while the page was hidden.                                                        |
| `.errors.denied`                        | Brauzer mikrofona icazə verməyib. İcazəni brauzerin sayt ayarlarından aça bilərsiniz; dinləməyə davam edirsiniz. | The browser has not allowed the microphone. You can allow it in the browser's site settings; you keep listening. |
| `.errors.no_mic`, `.errors.insecure`    | Mikrofon tapılmadı. · Canlı səs yalnız təhlükəsiz (https) ünvanda işləyir.                                       | No microphone was found. · Live voice works only on a secure (https) address.                                    |
| `.errors.unreachable`                   | Canlı səsə qoşulmaq alınmadı. Oyun davam edir.                                                                   | Could not join the live voice. The game goes on.                                                                 |
| `.errors.agreement`, `.errors.busy`     | Qoşulmaq üçün razılıq lazımdır. · Hazırda canlı səs üçün yer yoxdur. Bir az sonra yenidən cəhd edin.             | Joining needs your agreement. · There is no room for another live voice right now. Try again a little later.     |

`rooms.voice.agreement`, `.note.*`: decision 14. `rooms.sound.label`, `.unlock`: decision 25.

## Routes

All in the player group (`auth`, `role.chosen`, `verified`, `role:player`, `rooms.enabled`) and behind `rooms.voice`, which answers 404 while `RoomsArea::voice()` is false. `{room}` and `{member}` are bound as every room route binds them, so each goes into the isolation test.

| Method and path                            | Name                         | Reaches it                                                           | Answer                                      |
| ------------------------------------------ | ---------------------------- | -------------------------------------------------------------------- | ------------------------------------------- |
| `PUT /rooms/{room}/voice`                  | `rooms.voice.update`         | `voice`: the host, open room                                         | `SetRoomVoice`; 204 / 422 `busy`            |
| `PUT /rooms/{room}/members/{member}/voice` | `rooms.members.voice.update` | `voice`, a row of that room that is not the host's (`scopeBindings`) | `SetRoomPlayerVoice`; 204                   |
| `POST /rooms/{room}/voice/tickets`         | `rooms.voice.tickets.store`  | `listen`, `throttle:room-voice`                                      | `JoinRoomVoice`; `{url, token}` / 422 / 503 |

## The state

`RoomStateData::VERSION` becomes 3 (`z.literal(3)`; `room.changed` stays `{v: 1, revision}`):

```
RoomState { v: 3, …,                                                   // every other key as it is
  voice:   { on: bool, agreed: bool } | null,                          // null: the instance's voice is off, or a closed room
  players: [{ id, name | null, present, host,
              voice: open | muted | waiting | null }] }                // not null exactly for the host and the present players while voice.on
```

`agreed` is the viewer's own account's. A page shows nothing of voice while `voice` is null, the host's switch while `on` is false, and reads who may what from `players`. Who is connected, whose microphone is open and who speaks are never in the state. Fixtures: every `state.*.json` gains `voice: null` and `voice: null` on each player; `state.voice.json` (the start screen, the voice on, the viewer agreed; one player open, one muted, one waiting) and `state.voice.off.json` (the host's view, `on` false) are new.

## Consequences

- An instance decides in `.env` whether adults can talk in it, and an instance for schools never runs a voice server at all.
- A host can make a room safe to talk in without Elifba knowing anyone's age: nobody new is heard or hears until the host says so.
- Elifba can honestly say that it has nothing of what was said — and for the same reason can investigate nothing. A complaint about what someone said in a room has no record behind it.
- Each removal while the voice is on costs everyone else a gap of a second or two. A mute costs nothing.
- The host now has two published ports and a server reachable from the internet to keep patched; Dependabot watches the image's tag as it watches the others.
- Players on a network that allows only 80 and 443 have no voice in the first release.
- With voice, a room without a big screen becomes the usual one, and ADR 025's own reason to revisit ("a room played without a screen often enough that phones should carry the room's sounds") is likely met. That is a decision of its own, not this one's.
- A host's television playing the room's cues beside the host's open microphone is heard by the others as any noise in that home is; the screen's own toggle mutes it.

## Alternatives rejected

- **A cloud voice service** (LiveKit Cloud, Twilio, Agora, Daily): every word would pass through a company outside the instance, against frontend rule 9 and ADR 024 decision 11, and outside what `/privacy` can promise.
- **Phone to phone (a WebRTC mesh)**: 20 players are 380 one-way streams, each phone uploads its voice nineteen times, and every player learns every other's IP address unless a relay is forced — which is then a server anyway.
- **A public STUN server "just for discovery"**: a third-party request from every phone, for something a server with a public address does not need.
- **mediasoup, Janus, Jitsi Videobridge**: a Node library that leaves the signalling, the rooms and the tickets to be written; GPLv3; a part of a larger stack (Prosody, Jicofo). LiveKit is one Apache-2.0 binary with tickets, permissions and a server API.
- **The LiveKit PHP SDK** (community, protobuf and Twirp packages): the app needs one JWT and six JSON calls.
- **TURN over TLS on 443 in the first release**: see decision 4.
- **End-to-end encryption**: the key would be handed out by the same server it is meant to exclude, so it would be a promise without substance; `/privacy` says the truth instead. It returns when keys can be agreed without the operator.
- **Voice always on, or on by default**: the owner decided otherwise, and a room where everyone sits together would start with an echo.
- **An open microphone at a join, or a microphone remembered open**: nobody should be heard without having just chosen it.
- **The host unmuting a player's microphone**: a microphone turned on by someone else.
- **A newcomer who listens until admitted, or who is in at once**: a stranger with a guessed code would hear a private conversation; hearing is the harm, not only speaking.
- **A mute that also closes the voice room for all** (airtight against a replayed ticket at once): a gap for everyone at each mute; the sweep closes that hole within 15 seconds and removal closes it at once.
- **A webhook from the voice server**: a route into the app that no session guards, for a check the sweep already makes.
- **The screen as the voices' loudspeaker, or as a silent watcher that shows who speaks**: an echo in the first case; in the second a ticket and an identity for a page that is no player, to show what every phone already shows.
- **Push-to-talk**: the fingers are answering.
- **Muting everyone during a question**: the game would be driving the voice server, and a table does not work that way.
- **A nickname, an avatar or a photo for a speaker**: the same refusal as ADR 024 — free text or a picture that others see, with no moderation.
- **Recording "for safety", or keeping who spoke when**: the opposite of what the owner promised.
- **An age question or an "I am 18" tick before the voice**: ADR 024 rejected it, and an untrue tick still costs nothing.
- **The consent folded into the player role's sentence**: a stamped sentence would change under those who accepted it.
- **The other two prototypes**: B, «Başlıq» (the microphone as a round icon in the head, the speaker in the head's second line and on the names: no height, but an open microphone is only an icon, and the start screen grows); C, «Sıra» (a row of name pills, one's own name as the microphone: everyone visible, but names cut to their first word and moving during a question).

## What would make us revisit

A law that requires verifying age or recording consent where an instance runs; evidence that the voice is used to reach or harass anyone, a child above all — then the switch goes off first and the design is reopened; players who cannot connect from networks that allow only 443 often enough to matter (TURN over TLS, decision 4); rooms larger than 20; a request for video, recording or messages, each of which is a new decision and none of which this one prepares; a way to agree end-to-end keys without the operator.
