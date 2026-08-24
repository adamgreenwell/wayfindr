<?php

use App\Support\DatabaseKey;

/**
 * Tested directly because the failure it prevents cannot be reproduced by the
 * suite: SQLite compares an oversized numeric string to an integer key without
 * complaint, so an integration test passes whether or not this guard exists.
 * PostgreSQL -- what every documented install runs -- raises.
 */
test('a value inside the key range is a key', function (string $value): void {
    expect(DatabaseKey::isValid($value))->toBeTrue();
})->with(['1', '42', '0', '0042', (string) PHP_INT_MAX, '9223372036854775806']);

test('a value outside the key range is not', function (string $value): void {
    expect(DatabaseKey::isValid($value))->toBeFalse();
})->with([
    '9223372036854775808',            // PHP_INT_MAX + 1
    '999999999999999999999999999999',
    '10000000000000000000',
]);

test('anything that is not a run of digits is not a key', function (string $value): void {
    expect(DatabaseKey::isValid($value))->toBeFalse();
})->with(['', 'abc', '1a', '-1', '1.0', ' 1', '1 ', '٤٢']);

test('the guard is the same one every public id route uses', function (): void {
    // The range failure cannot be reproduced by this suite: SQLite compares an
    // oversized numeric string to an integer key without complaint, so removing
    // the guard from a controller leaves its integration test green. Only
    // PostgreSQL raises.
    //
    // So the predicate is proved here, and the routes are checked for using it
    // rather than for behaving differently without it.
    $routes = [
        'app/Http/Controllers/Api/V1/TicketController.php',
        'app/Http/Controllers/Api/V1/VisitorController.php',
        'app/Http/Controllers/AgentAccountApiTokenController.php',
    ];

    foreach ($routes as $path) {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        expect($source)->not->toBeFalse()
            ->and($source)->toContain('DatabaseKey::isValid');
    }
});
