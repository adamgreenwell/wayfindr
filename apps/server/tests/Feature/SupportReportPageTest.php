<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRating;
use App\Models\OperatorSetting;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\ReaderClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The install fact a migration stamps once. Tests state it, because in a test
 * the migration runs at "now" while fixtures are back-dated.
 */
function stampRecordingStart(CarbonImmutable $at): void
{
    OperatorSetting::query()->updateOrCreate(
        ['key' => 'reporting.lifecycle_recording_began_at'],
        ['value' => $at->toDateTimeString()],
    );
}

function reportPageWorld(AccountRole $role = AccountRole::Admin): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['name' => 'Primary Site']);
    $agent = User::factory()->for($account)->create(['account_role' => $role, 'name' => 'Ada Lead']);
    $visitor = Visitor::factory()->for($site)->create();

    return compact('account', 'site', 'agent', 'visitor');
}

test('an agent who is not an admin cannot open reports', function (): void {
    $world = reportPageWorld(AccountRole::Agent);

    $this->actingAs($world['agent'])->get('/dashboard/reports')->assertForbidden();
    $this->actingAs($world['agent'])->get('/dashboard/reports/export')->assertForbidden();
});

test('an admin sees every report section', function (): void {
    $world = reportPageWorld();

    $response = $this->actingAs($world['agent'])->get('/dashboard/reports')->assertOk();

    foreach (['Volume', 'Speed', 'Tickets', 'Agents', 'Satisfaction'] as $label) {
        $response->assertSee($label, false);
    }

    $response->assertSee('data-tab-panel="volume"', false)
        ->assertSee('data-tab-panel="speed"', false)
        ->assertSee('data-tab-panel="tickets"', false)
        ->assertSee('data-tab-panel="agents"', false)
        ->assertSee('data-tab-panel="satisfaction"', false);
});

test('an admin reads the report in their dashboard language', function (string $locale, array $copy): void {
    $world = reportPageWorld();
    $world['agent']->forceFill(['locale' => $locale])->save();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'"', false)
        ->assertSee($copy['title'])
        ->assertSee($copy['subtitle'])
        ->assertSee($copy['tickets_created'])
        ->assertSee($copy['tickets_closed'])
        ->assertSee($copy['tickets_open'])
        ->assertDontSee('How much support came in');
})->with([
    'German' => ['de', [
        'title' => 'Berichte',
        'subtitle' => 'Wie viel Support einging, wie schnell Antworten kamen und wer die Arbeit übernommen hat.',
        'tickets_created' => '0 Tickets erstellt',
        'tickets_closed' => '0 Tickets geschlossen',
        'tickets_open' => '0 Tickets derzeit offen',
    ]],
    'Italian' => ['it', [
        'title' => 'Report',
        'subtitle' => 'Quanto supporto è arrivato, quanto rapidamente ha ricevuto risposta e chi se ne è occupato.',
        'tickets_created' => '0 ticket creati',
        'tickets_closed' => '0 ticket chiusi',
        'tickets_open' => '0 ticket aperti ora',
    ]],
]);

test('a localized report keeps authored values language neutral and formats reader-facing values', function (): void {
    $world = reportPageWorld();
    $world['agent']->forceFill(['locale' => 'de'])->save();
    $world['site']->forceFill(['name' => 'Datenpunkt Reports Site'])->save();

    $worker = User::factory()->for($world['account'])->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Datenpunkt Reports Agent',
    ]);

    $rated = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'open',
        'support_code' => 'WF-RPTLANG',
        'created_at' => CarbonImmutable::now()->subHours(3),
    ]);
    ConversationMessage::factory()->for($rated)->create([
        'sender_type' => User::class,
        'sender_id' => $worker->id,
        'created_at' => CarbonImmutable::now()->subHours(2),
    ]);
    app(ConversationLifecycleLog::class)->closed($rated->fresh(), $worker, 'open');
    $rated->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    ConversationRating::factory()->for($rated)->for($world['site'])->create([
        'score' => 'bad',
        'comment' => 'Datenpunkt visitor comment',
        'rated_at' => CarbonImmutable::now()->subHour(),
        'episode_closed_at' => now(),
    ]);

    $good = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subHours(2),
    ]);
    app(ConversationLifecycleLog::class)->closed($good->fresh(), $world['agent'], 'open');
    $good->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    ConversationRating::factory()->for($good)->for($world['site'])->create([
        'score' => 'good',
        'episode_closed_at' => now(),
    ]);

    $anotherBad = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subHour(),
    ]);
    app(ConversationLifecycleLog::class)->closed($anotherBad->fresh(), $world['agent'], 'open');
    $anotherBad->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    ConversationRating::factory()->for($anotherBad)->for($world['site'])->create([
        'score' => 'bad',
        'episode_closed_at' => now(),
    ]);

    Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subHours(2)->subMinutes(15),
    ]);

    $html = (string) $this->actingAs($world['agent'])
        ->get(route('dashboard.reports.index', ['report_days' => 7]))
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('2 Stunden 15 Minuten')
        ->assertSee('33,3'."\u{00A0}".'%')
        ->assertSee(CarbonImmutable::now()->subDay()->locale('de')->isoFormat('D MMM'))
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    foreach ([
        'site name' => '//option[normalize-space(text())="Datenpunkt Reports Site"]',
        'agent name' => '//span[normalize-space(text())="Datenpunkt Reports Agent"]',
        'visitor comment' => '//td[normalize-space(text())="Datenpunkt visitor comment"]',
        'support code' => '//a[normalize-space(text())="WF-RPTLANG"]',
    ] as $label => $query) {
        $node = $xpath->query($query)->item(0);

        expect($node)->toBeInstanceOf(DOMElement::class, "the {$label} did not render")
            ->and($node->hasAttribute('lang'))->toBeTrue("the {$label} inherits the dashboard language")
            ->and($node->getAttribute('lang'))->toBe('', "the {$label} claims a language the product cannot know");
    }

    $translatedScore = $xpath->query('//td[normalize-space(text())="Schlecht"]')->item(0);

    expect($translatedScore)->toBeInstanceOf(DOMElement::class, 'the translated rating did not render')
        ->and($translatedScore->hasAttribute('lang'))->toBeFalse('translated interface copy was marked as authored data');
});

test('a removed agent is described in the dashboard language', function (): void {
    $world = reportPageWorld();
    $world['agent']->forceFill(['locale' => 'de'])->save();
    $removed = User::factory()->for($world['account'])->create(['name' => 'Gone Datenpunkt Agent']);
    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'created_at' => CarbonImmutable::now()->subDay(),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $removed->id,
        'created_at' => CarbonImmutable::now()->subHours(12),
    ]);
    $removed->delete();

    $html = (string) $this->actingAs($world['agent'])
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSee('Entfernter Agent')
        ->assertDontSee('Removed agent')
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $fallback = (new DOMXPath($document))->query('//td[normalize-space(text())="Entfernter Agent"]')->item(0);

    expect($fallback)->toBeInstanceOf(DOMElement::class, 'the removed-agent fallback did not render')
        ->and($fallback->hasAttribute('lang'))->toBeFalse('translated interface copy was marked as authored data');
});

test('the Italian satisfaction summary inflects closes independently of answers', function (int $closes, int $answers, string $expected): void {
    app()->setLocale('it');

    expect(trans_choice('reports.satisfaction.summary', $closes, [
        'answered' => $answers,
        'closed' => $closes,
    ]))->toBe($expected);
})->with([
    'no answers to one close' => [1, 0, 'Risposte: 0 su 1 chiusura'],
    'one answer to two closes' => [2, 1, 'Risposte: 1 su 2 chiusure'],
]);

test('an empty closed series is explained rather than left to be guessed at', function (): void {
    $world = reportPageWorld();

    stampRecordingStart(CarbonImmutable::now()->subDays(3));

    // Nothing has ever been closed. The flat line is real, but a reader cannot
    // tell a quiet month from an unrecorded one without the date.
    $this->actingAs($world['agent'])->get('/dashboard/reports')
        ->assertOk()
        ->assertSee('this install began keeping on', false)
        ->assertSee(ReaderClock::date(CarbonImmutable::now()->subDays(3)), false);
});

test('the report dates the start of lifecycle recording once it exists', function (): void {
    $world = reportPageWorld();

    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'closed',
        'created_at' => CarbonImmutable::now()->subDays(3),
    ]);
    $conversation->auditEvents()->create([
        'account_id' => $world['account']->id,
        'site_id' => $world['site']->id,
        'action' => ConversationLifecycleLog::CLOSED,
        'metadata' => ['previous_status' => 'open', 'actor' => 'agent'],
        'occurred_at' => CarbonImmutable::now()->subDays(2),
    ]);

    stampRecordingStart(CarbonImmutable::now()->subDays(2));

    $this->actingAs($world['agent'])->get('/dashboard/reports?report_days=30')
        ->assertOk()
        ->assertSee('this install began keeping on', false)
        ->assertSee(ReaderClock::date(CarbonImmutable::now()->subDays(2)), false);
});

test('a range outside the offered choices falls back rather than failing', function (): void {
    $world = reportPageWorld();

    // A stale bookmark is not an attack and should not be an error page.
    $this->actingAs($world['agent'])->get('/dashboard/reports?report_days=9999')
        ->assertOk()
        ->assertSee('Last 30 days', false);

    $this->actingAs($world['agent'])->get('/dashboard/reports?report_days=not-a-number')
        ->assertOk();

    $this->actingAs($world['agent'])->get('/dashboard/reports?report_site=not-a-number')
        ->assertOk();
});

test('the daily export carries one row per day in the range', function (): void {
    $world = reportPageWorld();
    // CSV is a machine-facing contract. Keep its stable English headers and
    // raw numeric cells even when the reader-facing page is German.
    $world['agent']->forceFill(['locale' => 'de'])->save();

    Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subDays(1)->setTime(9, 0),
    ]);

    $response = $this->actingAs($world['agent'])
        ->get('/dashboard/reports/export?report_days=7&report_export=daily')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));

    expect($lines[0])->toBe('date,conversations_opened,conversations_closed')
        // Seven days plus the header.
        ->and($lines)->toHaveCount(8)
        ->and(implode("\n", $lines))->toContain(CarbonImmutable::now()->subDay()->format('Y-m-d').',1,0');
});

test('the agent export cannot smuggle a formula into a spreadsheet', function (): void {
    $world = reportPageWorld();
    $world['agent']->forceFill(['locale' => 'it'])->save();
    $mischief = User::factory()->for($world['account'])->create([
        'account_role' => AccountRole::Agent,
        'name' => '=HYPERLINK("http://example.test","click")',
    ]);

    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subDays(2),
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $mischief->id,
        'created_at' => CarbonImmutable::now()->subDays(2)->addMinutes(3),
    ]);

    $content = $this->actingAs($world['agent'])
        ->get('/dashboard/reports/export?report_export=agents')
        ->assertOk()
        ->streamedContent();

    // The leading apostrophe is what stops a spreadsheet evaluating the cell.
    expect($content)->toContain("'=HYPERLINK")
        ->and($content)->toContain('agent,email,replies_sent,conversations_closed');
});

test('the export cannot be pointed at another account', function (): void {
    $world = reportPageWorld();
    $stranger = reportPageWorld();

    Conversation::factory()->for($stranger['site'])->for($stranger['visitor'])->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subDays(1),
    ]);

    $content = $this->actingAs($world['agent'])
        ->get('/dashboard/reports/export?report_days=7&report_site='.$stranger['site']->id)
        ->assertOk()
        ->streamedContent();

    // Every day is zero: the stranger's conversation is not reachable, and the
    // rejected site id fell back to this admin's own sites rather than theirs.
    $rows = array_slice(array_values(array_filter(explode("\n", trim($content)))), 1);

    foreach ($rows as $row) {
        expect($row)->toEndWith(',0,0');
    }
});

test('reports are absent from the sidebar for a non-admin agent', function (): void {
    $world = reportPageWorld(AccountRole::Agent);

    $this->actingAs($world['agent'])->get('/dashboard')
        ->assertOk()
        ->assertDontSee('/dashboard/reports"', false);

    $admin = User::factory()->for($world['account'])->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        // route() renders an absolute URL, so match the tail.
        ->assertSee('/dashboard/reports"', false);
});

test('a day with nothing on it draws no bar', function (): void {
    $world = reportPageWorld();
    stampRecordingStart(CarbonImmutable::now()->subDays(30));

    // One busy day in an otherwise empty week.
    for ($i = 0; $i < 3; $i++) {
        Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
            'status' => 'open',
            'created_at' => CarbonImmutable::now()->subDays(3)->setTime(9 + $i, 0),
        ]);
    }

    $html = $this->actingAs($world['agent'])->get('/dashboard/reports?report_days=7')->assertOk()->getContent();

    preg_match_all('/<div class="chart__bar chart__bar--(opened|closed)([^"]*)" style="height: ([\d.]+)%"/', $html, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(14);

    foreach ($matches as $bar) {
        $isZero = (float) $bar[3] === 0.0;
        $marked = str_contains($bar[2], 'chart__bar--none');

        // The minimum height that keeps a single conversation visible would
        // otherwise draw a sliver on every empty day, so a quiet week would
        // read as a busy one.
        expect($marked)->toBe($isZero);
    }

    // Marking the element is not the same as the browser drawing nothing, and
    // asserting only the marker is how the first version of this fix passed
    // while the sliver survived: at equal specificity `--closed` came after
    // `--none` and put its 1px border back. The override has to win the
    // cascade, so it is both more specific and later.
    $none = strpos($html, '.chart__bar.chart__bar--none');
    $closed = strpos($html, '.chart__bar--closed {');

    expect($none)->not->toBeFalse()
        ->and($closed)->not->toBeFalse()
        ->and($none)->toBeGreaterThan($closed);
});

test('an all-legacy sample explains itself instead of claiming nothing closed', function (): void {
    $world = reportPageWorld();

    stampRecordingStart(CarbonImmutable::now()->subDays(2));

    // Closed inside the window, but opened long before recording began, so the
    // duration cannot be established.
    $ancient = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => 'closed',
        'created_at' => CarbonImmutable::now()->subDays(90),
    ]);
    $ancient->auditEvents()->create([
        'account_id' => $world['account']->id,
        'site_id' => $world['site']->id,
        'action' => ConversationLifecycleLog::CLOSED,
        'metadata' => ['previous_status' => 'open', 'actor' => 'agent'],
        'occurred_at' => CarbonImmutable::now()->subDay(),
    ]);

    // Every close in range is unmeasurable, so the summary is empty. Saying
    // "no conversation was closed" would be false, and would hide the
    // explanation in the one case that most needs it.
    $this->actingAs($world['agent'])->get('/dashboard/reports?report_days=7')
        ->assertOk()
        ->assertDontSee('No conversation was closed in this period', false)
        ->assertSee('opened before this install started recording reopens', false);
});
