<?php

use App\Domain\Play\Support\PairingCode;
use App\Domain\Rooms\Support\RoomCode;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * The room code (docs/architecture.md §12 "Rooms and joining", ADR 020
 * decision 3): six digits, unique by the column's index — `issue()` tries
 * the next code when the store meets a taken one — and read from what a
 * player typed. It is a code space of its own: nothing in it is a pairing
 * code's.
 */

/**
 * A store that fails like the unique index for the codes in `$taken`.
 *
 * @param  list<string>  $taken
 * @param  list<string>  $tried
 * @return Closure(string): string
 */
function roomCodeStore(array $taken, array &$tried): Closure
{
    return static function (string $code) use ($taken, &$tried): string {
        $tried[] = $code;

        if (in_array($code, $taken, true)) {
            throw new UniqueConstraintViolationException('sqlite', 'insert into "rooms"', [], new RuntimeException('UNIQUE constraint failed: rooms.code'));
        }

        return $code;
    };
}

it('draws six digits, leading zeros kept', function (): void {
    $codes = new RoomCode;
    $tried = [];

    foreach (range(1, 200) as $_) {
        expect($codes->issue(roomCodeStore([], $tried)))->toMatch('/^\d{6}$/');
    }

    expect((new RoomCode(static fn (): string => '000042'))->issue(roomCodeStore([], $tried)))->toBe('000042');
});

it('tries the next code when the store meets a taken one', function (): void {
    $queue = ['111111', '222222', '333333'];
    $codes = new RoomCode(static function () use (&$queue): string {
        return array_shift($queue) ?? throw new RuntimeException('No more codes.');
    });
    $tried = [];

    expect($codes->issue(roomCodeStore(['111111', '222222'], $tried)))->toBe('333333')
        ->and($tried)->toBe(['111111', '222222', '333333']);
});

it('gives up after twenty taken codes', function (): void {
    $codes = new RoomCode(static fn (): string => '123456');
    $tried = [];

    expect(function () use ($codes, &$tried): void {
        $codes->issue(roomCodeStore(['123456'], $tried));
    })->toThrow(RuntimeException::class, 'No free room code after 20 attempts.')
        ->and($tried)->toHaveCount(20);
});

it('reads six digits from what a player typed', function (mixed $typed, ?string $code): void {
    expect(RoomCode::normalize($typed))->toBe($code);
})->with([
    'six digits' => ['482913', '482913'],
    'with a space' => ['482 913', '482913'],
    'with a dash' => ['482-913', '482913'],
    'with spaces around' => [' 482 913 ', '482913'],
    'an integer' => [482913, '482913'],
    'five digits' => ['48291', null],
    'seven digits' => ['4829130', null],
    'letters' => ['48291a', null],
    'a newline at the end' => ["482913\n", '482913'],
    'empty' => ['', null],
    'null' => [null, null],
    'a list' => [['482913'], null],
]);

it('shares nothing with the pairing code', function (): void {
    $source = (string) file_get_contents((new ReflectionClass(RoomCode::class))->getFileName() ?: '');

    expect($source)->not->toContain('PairingCode')
        ->and($source)->not->toContain('Domain\\Play')
        ->and(RoomCode::class)->not->toBe(PairingCode::class)
        ->and(get_parent_class(RoomCode::class))->toBeFalse();
});
