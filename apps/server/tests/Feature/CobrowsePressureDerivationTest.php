<?php

// `has_pressure` is derived from the COUNTS, not by reading back the English
// sentence that renders them.
//
// The render side was fixed when the cobrowse vocabulary was extracted for
// translation -- `CobrowsePressureSentence` and a `has_pressure` state key
// ship -- but the DERIVATION was not. It reverse-engineered the boolean by
// comparing the formatted string against two English literals, so the first
// person to translate `CobrowseTransportPressure::format()` would have made
// every session read as degraded to every German and Italian agent,
// permanently, with nothing in that diff to suggest why.

use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\CobrowseConsentState;
use App\Support\CobrowseTransportPressure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function pressureTransportFor(array $telemetry): array
{
    $site = Site::factory()->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    CobrowseSession::query()->create([
        'conversation_id' => $conversation->id,
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'status' => 'granted',
        'consented_at' => now(),
        'metadata' => ['telemetry' => $telemetry + ['reported_at' => now()->toJSON()]],
    ]);

    return app(CobrowseConsentState::class)->forConversation($conversation->fresh())['transport'];
}

test('pressure is reported when batches were dropped', function (): void {
    $transport = pressureTransportFor(['dropped_batches' => 3]);

    expect($transport['has_pressure'])->toBeTrue()
        ->and($transport['pressure_counts']['dropped_batches'])->toBe(3);
});

test('no pressure is reported when nothing was dropped or skipped', function (): void {
    $transport = pressureTransportFor(['dropped_batches' => 0, 'skipped_mutations' => 0]);

    expect($transport['has_pressure'])->toBeFalse()
        ->and($transport['pressure_counts']['dropped_batches'])->toBe(0);
});

test('the pressure flag does not move when the sentence changes language', function (): void {
    // The guard against the regression this file exists for. Translating that
    // sentence is exactly what extracting this surface will do, and the flag
    // must not notice.
    $quiet = ['dropped_batches' => 0, 'skipped_mutations' => 0];

    expect(pressureTransportFor($quiet)['has_pressure'])->toBeFalse();

    app()->bind(CobrowseTransportPressure::class, fn () => new class extends CobrowseTransportPressure
    {
        public function format(array $metadata, ?Carbon $latestReport = null): string
        {
            // A translated build. Not one of the English literals the old
            // derivation compared against.
            return 'Keine Aussetzer gemeldet';
        }
    });

    expect(pressureTransportFor($quiet)['has_pressure'])->toBeFalse(
        'the pressure flag moved because the sentence changed language'
    );
});
