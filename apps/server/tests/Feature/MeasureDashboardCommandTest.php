<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('it reports a figure for every page it claims to measure', function (): void {
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 20, '--messages' => 3])->assertSuccessful();

    $this->artisan('wayfindr:measure-dashboard', ['--runs' => 1, '--json' => true])
        ->assertSuccessful();
});

test('every measured page answers 200, or its timing means nothing', function (): void {
    // A page that 403s or 500s is quick, and a baseline full of quick numbers
    // reads like good news. The command warns when it sees one; this makes the
    // suite refuse it, because a route renamed out from under the list is how a
    // performance baseline quietly stops measuring anything.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 20, '--messages' => 3])->assertSuccessful();

    // `Artisan::call` rather than `$this->artisan()`: the Pest helper returns a
    // pending assertion object and does not fill the output buffer, so reading
    // it back gave null and there was nothing to assert over.
    $exit = Artisan::call('wayfindr:measure-dashboard', ['--runs' => 1, '--json' => true]);

    expect($exit)->toBe(0);

    $measured = json_decode(Artisan::output(), true);

    expect($measured)->toBeArray()
        ->and($measured['pages'] ?? [])->not->toBeEmpty();

    foreach ($measured['pages'] as $page) {
        expect($page['status'])->toBe(200, "{$page['page']} answered {$page['status']} at {$page['uri']}");
        expect($page['bytes'])->toBeGreaterThan(0);
        expect($page['queries'])->toBeGreaterThan(0);
    }

});

test('the detail page is the control, and does not grow with the desk', function (): void {
    // The queues grow with the desk and the conversation DETAIL page does not.
    // That contrast is the whole finding, so it is asserted rather than assumed
    // -- and it needs two SIZES to be visible at all.
    //
    // The first version compared the two pages at one size and asserted the
    // detail page was the smaller. At twenty conversations it is the larger:
    // it carries about 150KB of fixed chrome while a twenty-row queue is tiny.
    // The assertion was true only at the scale I happened to have in front of
    // me, which is the opposite of what a baseline is for.
    $measure = function (): array {
        Artisan::call('wayfindr:measure-dashboard', ['--runs' => 1, '--json' => true]);

        return collect(json_decode(Artisan::output(), true)['pages'])
            ->keyBy('page')
            ->all();
    };

    Artisan::call('wayfindr:seed-desk', ['--conversations' => 20, '--messages' => 3, '--fresh' => true]);
    $small = $measure();

    Artisan::call('wayfindr:seed-desk', ['--conversations' => 400, '--messages' => 3, '--fresh' => true]);
    $large = $measure();

    // The queue grew with the data, so the sizes really are different.
    expect($large['Conversation queue (open)']['bytes'])
        ->toBeGreaterThan($small['Conversation queue (open)']['bytes'] * 3,
            'the queue did not grow, so this comparison is measuring nothing');

    // The detail page did not. Its query count is fixed, and its size moves
    // only by the handful of bytes a different support code takes.
    expect($large['Conversation detail']['queries'])
        ->toBe($small['Conversation detail']['queries'],
            'the conversation detail page now issues more queries on a larger desk');

    expect(abs($large['Conversation detail']['bytes'] - $small['Conversation detail']['bytes']))
        ->toBeLessThan(20000, 'the conversation detail page now grows with the size of the desk');
});

test('it says so rather than measuring nothing when the database is empty', function (): void {
    $this->artisan('wayfindr:measure-dashboard')->assertFailed();
});

test('it measures a conversation the agent can actually open', function (): void {
    // A global "highest id" pick finds whatever conversation was created last,
    // which in a database holding more than one account is one the measured
    // agent cannot view. The request 404s, a 404 is very fast, and it would
    // have been reported as the best number on the page.
    //
    // Nothing else in the suite puts a second account in front of this command,
    // which is why the global query looked correct.
    Artisan::call('wayfindr:seed-desk', ['--conversations' => 20, '--messages' => 2, '--fresh' => true]);

    $stranger = Account::query()->create(['slug' => 'another-desk', 'name' => 'Another']);
    $strangerSite = Site::factory()->for($stranger)->create();
    $strangerVisitor = Visitor::factory()->for($strangerSite)->create();

    // Created LAST, so it holds the highest id and a global query finds it.
    Conversation::factory()->for($strangerSite)->for($strangerVisitor)
        ->create(['support_code' => 'WF-STRANGER-1']);

    $exit = Artisan::call('wayfindr:measure-dashboard', ['--runs' => 1, '--json' => true]);

    expect($exit)->toBe(0, 'the command measured a page the agent cannot open');

    $measured = json_decode(Artisan::output(), true);
    $detail = collect($measured['pages'])->firstWhere('page', 'Conversation detail');

    expect($detail['status'])->toBe(200)
        ->and($detail['uri'])->not->toContain('WF-STRANGER-1');
});
