<?php

use Symfony\Component\Finder\Finder;

/*
 * The room pages case nothing (docs/frontend.md rule 15, CLAUDE.md
 * "Rooms"): a letter, a word or a name is drawn as the server wrote it.
 * Outside an Azerbaijani locale CSS's `uppercase` and JavaScript's
 * `toUpperCase()` turn «i» into «I» and lose «ı» and «İ» — the very
 * letters «Düzgün yazılış» asks about and «Anaqram» tiles show (B6).
 * Comments may name what is forbidden; code may not use it.
 */

it('changes the case of no text on a room page', function (): void {
    $files = Finder::create()
        ->files()
        ->in([
            resource_path('js/components/rooms'),
            resource_path('js/pages/rooms'),
            resource_path('js/rooms'),
        ])
        ->name(['*.ts', '*.tsx'])
        ->notName('*.test.ts');

    $offenders = [];

    foreach ($files as $file) {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $file->getContents());

        if (preg_match('/\b(uppercase|lowercase|capitalize)\b|to(Locale)?(Upper|Lower)Case\s*\(/', $code, $match) === 1) {
            $offenders[] = $file->getRelativePathname().': '.$match[0];
        }
    }

    expect(iterator_count($files))->toBeGreaterThan(10)
        ->and($offenders)->toBe([]);
});
