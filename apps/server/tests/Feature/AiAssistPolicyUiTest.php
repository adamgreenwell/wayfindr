<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('policy task controls render translated Summary labels without changing the submitted task', function (string $locale, string $summary): void {
    Http::preventStrayRequests();
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner, 'locale' => $locale]);
    Site::factory()->for($owner->account)->create();

    $response = $this->actingAs($owner)->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    foreach (['ai-policy-account-summary', 'ai-policy-sites-summary'] as $id) {
        $label = $xpath->query('//label[@for="'.$id.'"]')->item(0);
        expect($label)->not->toBeNull()
            ->and(trim($label->textContent))->toBe($summary)
            ->and($xpath->query('//input[@id="'.$id.'" and @name="summary"]')->length)->toBe(1);
    }
    expect($xpath->query('//input[@id="ai-policy-account-mode-manual" and @checked]')->length)->toBe(1, 'An untouched installation keeps its legacy Manual choice.')
        ->and($xpath->query('//input[@id="ai-policy-sites-mode-prepare" and @checked]')->length)->toBe(1, 'Deliberately enabling sites starts with Prepare configuration.');
    expect($response->getContent())->not->toContain('ai_assist.policy.tasks.');
})->with([
    'English' => ['en', 'Summary'],
    'German' => ['de', 'Zusammenfassung'],
    'Italian' => ['it', 'Riepilogo'],
]);
