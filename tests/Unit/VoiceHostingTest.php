<?php

use Symfony\Component\Yaml\Yaml;

/*
 * What the voice's hosting files promise (docs/architecture.md §10 "Live
 * voice", ADR 031 decisions 19, 20, 27 and 32, "As built (C5, audit)"), read
 * from the files themselves: `scripts/docker-smoke.sh` runs them, and these
 * keep a change that would undo one from passing `composer ci:check`.
 */

/** @return array<string, mixed> */
function hostingYaml(string $path): array
{
    /** @var array<string, mixed> */
    return Yaml::parseFile(base_path($path));
}

function hostingFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

it('lets the voice server forward voice and nothing else, and log no join', function (): void {
    foreach (['docker/voice/livekit.yaml', 'docker/voice/livekit.dev.yaml'] as $path) {
        expect(hostingYaml($path)['room']['enabled_codecs'] ?? null)->toBe([['mime' => 'audio/opus'], ['mime' => 'audio/red']], $path);
    }

    // At `info` every join writes the phone's model, system, browser and addresses.
    expect(hostingYaml('docker/voice/livekit.yaml')['logging'] ?? null)->toBe(['level' => 'warn']);
});

it('keeps the ticket, the join request, the browser and the whole address out of Caddy\'s log', function (): void {
    $caddy = hostingFile('docker/caddy/Caddyfile');
    preg_match('/^\{\n(.*?)^\}/ms', $caddy, $global);
    $options = $global[1] ?? '';

    expect($options)->toContain('log default {')
        ->and($options)->toMatch('/request>uri query \{\s*delete access_token\s*delete join_request\s*\}/')
        ->and($options)->toContain('request>headers delete')
        ->and($options)->toMatch('/request>remote_ip ip_mask \{\s*ipv4 16\s*ipv6 32\s*\}/')
        ->and($options)->toMatch('/request>client_ip ip_mask \{\s*ipv4 16\s*ipv6 32\s*\}/')
        // That is the one `log` of the file: no access log anywhere.
        ->and(preg_match_all('/^\s*log\b/m', $caddy))->toBe(1);
});

it('routes the two paths of the voice library as the browser sent them, without the site\'s cookies, and answers every other /rtc itself', function (): void {
    $caddy = hostingFile('docker/caddy/Caddyfile');

    expect($caddy)->toMatch('/@voice \{\s*path \/rtc\/v1 \/rtc\/v1\/validate\s*expression `"\{\$ELIFBA_VOICE_ROUTE\}" == "on" && \{http\.request\.orig_uri\.path\} in \[\'\/rtc\/v1\', \'\/rtc\/v1\/validate\'\]`\s*\}/')
        ->and($caddy)->toMatch('/handle @voice \{\s*reverse_proxy voice:7880 \{\s*header_up -Cookie\s*\}\s*\}/')
        ->and($caddy)->toMatch('/@rtc path \/rtc \/rtc\/\*\s*handle @rtc \{\s*respond 404\s*\}/')
        // The voice route comes before the catch-all for /rtc, and both before the app's.
        ->and($caddy)->toMatch('/handle @voice .*handle @rtc .*\n\thandle \{\n/s');
});

it('gives every service a log of a fixed size and leaves the cache out of a backup', function (): void {
    $services = hostingYaml('docker-compose.yml')['services'];

    expect(array_keys($services))->toEqualCanonicalizing(['proxy', 'app', 'reverb', 'scheduler', 'db', 'backup', 'voice']);

    foreach ($services as $name => $service) {
        expect($service['logging'] ?? null)->toBe(['driver' => 'json-file', 'options' => ['max-size' => '5m', 'max-file' => '3']], $name);
    }

    expect(hostingFile('docker/backup/elifba-backup.sh'))->toMatch('/pg_dump [^\n]*\\\\\n\s*--exclude-table-data=cache --exclude-table-data=cache_locks/');
});
