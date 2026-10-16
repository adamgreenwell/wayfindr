<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function preparationThrottleRoute(array $world, string $task, string $action = 'show'): string
{
    return route('dashboard.conversations.assist.'.$action, ['supportCode' => $world['conversation']->support_code, 'task' => $task]);
}

test('status reads have a per-task limit while other tasks actors and Use stay independent', function (string $purpose, string $task): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    $this->actingAs($world['owner']);
    for ($index = 0; $index < 60; $index++) {
        $this->getJson(preparationThrottleRoute($world, $task))->assertOk();
    }
    expect($this->getJson(preparationThrottleRoute($world, $task))->status())->toBe(429, 'The sixty-first same-task status read must be throttled.');
    $other = $task === 'summary' ? 'reply' : 'summary';
    $this->getJson(preparationThrottleRoute($world, $other))->assertOk();
    $generation = $world['class']::query()->sole()->generation;
    $this->getJson(preparationThrottleRoute($world, $task, 'use').'?generation='.$generation)->assertOk();
    $this->actingAs($world['agent'])->getJson(preparationThrottleRoute($world, $task))->assertOk();
    expect(DB::table('ai_usage_attempts')->count())->toBe(1);
    Queue::assertPushed($world['job'], 1);
})->with([
    ['conversation_summary', 'summary'], ['conversation_reply_draft', 'reply'],
    ['conversation_knowledge_suggestion', 'knowledge'], ['conversation_ticket_suggestion', 'ticket'],
]);

test('status reads share a bounded aggregate budget without exhausting any single-task budget', function (): void {
    $world = assistPreparationWorld();
    $this->actingAs($world['owner']);
    foreach (['summary', 'reply', 'knowledge', 'ticket'] as $task) {
        for ($index = 0; $index < 45; $index++) {
            $this->getJson(preparationThrottleRoute($world, $task))->assertOk();
        }
    }
    $response = $this->getJson(preparationThrottleRoute($world, 'summary'));
    expect($response->status())->toBe(429, 'The aggregate status budget must bound an actor across tasks.');
    $response->assertHeader('Retry-After');
    $this->actingAs($world['agent'])->getJson(preparationThrottleRoute($world, 'summary'))->assertOk();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('Use has its own limit without consuming status or ensure admission', function (): void {
    $world = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    $generation = $world['class']::query()->sole()->generation;
    $url = preparationThrottleRoute($world, 'reply', 'use').'?generation='.$generation;
    $this->actingAs($world['owner']);
    for ($index = 0; $index < 60; $index++) {
        $this->getJson($url)->assertOk();
    }
    expect($this->getJson($url)->status())->toBe(429, 'The sixty-first Use must be throttled independently of reads.');
    $this->getJson(preparationThrottleRoute($world, 'reply'))->assertOk();
    $this->postJson(preparationThrottleRoute($world, 'reply', 'store'), ['action' => 'open', 'workspace' => 'conversation'])->assertOk()->assertJsonPath('queued', false);
    expect(DB::table('ai_usage_attempts')->count())->toBe(1);
    Queue::assertPushed($world['job'], 1);
});

test('ensure traffic cannot consume the ordinary visitor-export throttle', function (): void {
    $world = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    $this->actingAs($world['owner']);
    $url = preparationThrottleRoute($world, 'reply', 'store');
    for ($index = 0; $index < 60; $index++) {
        $this->postJson($url, ['action' => 'open', 'workspace' => 'conversation'])->assertOk()->assertJsonPath('queued', false);
    }
    expect($this->postJson($url, ['action' => 'open', 'workspace' => 'conversation'])->status())->toBe(429, 'Ensure must retain its bounded admission throttle.');
    $response = $this->post(route('dashboard.visitors.data-export', $world['conversation']->visitor));
    try {
        expect($response->getStatusCode())->toBe(200, 'AI ensure requests must never consume the ordinary export throttle.');
        $response->assertDownload();
    } finally {
        if ($response->baseResponse instanceof BinaryFileResponse) {
            $file = $response->baseResponse->getFile()->getPathname();
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    $this->getJson(preparationThrottleRoute($world, 'reply'))->assertOk();
    expect(DB::table('ai_usage_attempts')->count())->toBe(1);
    Queue::assertPushed($world['job'], 1);
});
