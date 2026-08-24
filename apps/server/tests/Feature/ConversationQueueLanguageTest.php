<?php

// The conversation queue in two languages (#749). This is the surface an agent
// looks at most, and the one where the copy is least visible in the view: the
// Blade file holds about seven sentences and the controller builds sixty.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Named for this file rather than for the concept. Pest helpers are global, and
 * a name like `queueWorld()` collides with whatever the next file wants.
 *
 * @return array{account: Account, site: Site, agents: array<string, User>}
 */
function conversationQueueLanguageWorld(int $conversations = 3): array
{
    // Data with a German-looking token in it, so the test can tell an account's
    // DATA apart from its copy: a name renders identically in both languages
    // and should.
    $account = Account::factory()->create(['name' => 'Acme Datenpunkt']);
    $agentFor = fn (Account $a): User => User::factory()->for($a)->create(['name' => 'Sender Datenpunkt']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Datenpunkt Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-datenpunkt']);

    // NOT range(1, $conversations): PHP counts DOWN when the end is lower
    // than the start, so range(1, 0) is [1, 0] and an "empty" world got two
    // conversations -- which quietly made the first-run test render a
    // populated queue.
    for ($i = 1; $i <= $conversations; $i++) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-LANG0'.$i,
            'subject' => 'Datenpunkt checkout '.$i,
            'status' => 'open',
        ]);

        // Rows with no messages at all reach only ONE of the preview branches
        // and none of the wait labels, so a world of empty conversations would
        // leave most of this surface's copy unrendered and unmeasured.
        if ($i === 1) {
            continue;
        }

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => $i % 2 === 0 ? Visitor::class : User::class,
            'sender_id' => $i % 2 === 0 ? $visitor->id : $agentFor($account)->id,
            'body' => 'Datenpunkt message body '.$i,
        ]);
    }

    return [
        'account' => $account,
        'site' => $site,
        'agents' => [
            'en' => User::factory()->for($account)->create(['locale' => 'en', 'name' => 'Ada Datenpunkt']),
            'de' => User::factory()->for($account)->create(['locale' => 'de', 'name' => 'Ada Datenpunkt']),
        ],
    ];
}

/**
 * @return array<int, string>
 */
function conversationQueueLanguageSentences(string $html): array
{
    // The page's own region. The topbar, search and navigation belong to the
    // app shell, which extracts with itself.
    if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $main) === 1) {
        $html = $main[1];
    }

    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $lines = preg_split('/[\r\n]+/u', html_entity_decode(strip_tags($html))) ?: [];

    return collect($lines)
        ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''))
        ->filter(fn (string $line): bool => mb_strlen($line) >= 25)
        // Data rather than copy, and correctly identical in both languages.
        ->reject(fn (string $line): bool => str_contains($line, 'Datenpunkt')
            || str_contains($line, 'WF-LANG')
            || str_contains($line, '@'))
        ->unique()
        ->values()
        ->all();
}

test('nothing on the conversation queue reads the same in both languages', function (): void {
    // Rendered in the states an agent actually reaches, not just the default
    // one. Every miss review has found on this epic so far has been on a
    // branch: a lane nobody selected, a search that matched nothing, a queue
    // that was empty for a different reason than the one the default shows.
    $world = conversationQueueLanguageWorld();

    $states = [
        'default' => [],
        'lane with no matches' => ['conversation_filter' => 'assigned_to_me'],
        'attention lane' => ['conversation_filter' => 'new_activity'],
        'search with no matches' => ['conversation_search' => 'zzzz-nothing-matches'],
        'closed' => ['conversation_filter' => 'closed'],
        'presence refinement' => ['conversation_presence' => 'quiet'],
    ];

    foreach ($states as $label => $query) {
        $inEnglish = conversationQueueLanguageSentences(
            $this->actingAs($world['agents']['en'])->get(route('dashboard.conversations.index', $query))->getContent()
        );
        $inGerman = conversationQueueLanguageSentences(
            $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index', $query))->getContent()
        );

        expect(array_values(array_intersect($inEnglish, $inGerman)))
            ->toBe([], "untranslated copy in state: {$label}");
    }
});

test('the first-run queue is translated, which no populated state reaches', function (): void {
    // A different empty state from "your filters matched nothing", with its own
    // copy and its own action, reachable only on an install where no visitor
    // has ever opened the widget.
    $world = conversationQueueLanguageWorld(conversations: 0);

    $inEnglish = conversationQueueLanguageSentences(
        $this->actingAs($world['agents']['en'])->get(route('dashboard.conversations.index'))->getContent()
    );
    $inGerman = conversationQueueLanguageSentences(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($inEnglish)->not->toBe([])
        ->and(array_values(array_intersect($inEnglish, $inGerman)))->toBe([]);
});

test('counts choose their plural form in each language, verb included', function (): void {
    // The reason these go through trans_choice rather than an inline ternary:
    // English inflects the verb for number and German does not, so a count
    // label built as noun-plus-chosen-verb is right in one language by luck.
    $singular = conversationQueueLanguageWorld(conversations: 1);

    $this->actingAs($singular['agents']['en'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('1 conversation matching the current queue filters')
        ->assertDontSee('1 conversations');

    $this->actingAs($singular['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('1 Unterhaltung ')
        ->assertDontSee('1 Unterhaltungen');
});

test('a plural count reads as a plural in both languages', function (): void {
    $several = conversationQueueLanguageWorld(conversations: 3);

    $this->actingAs($several['agents']['en'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('3 conversations matching the current queue filters');

    $this->actingAs($several['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('3 Unterhaltungen');
});
