<?php

// The conversation queue in two languages (#749). This is the surface an agent
// looks at most, and the one where the copy is least visible in the view: the
// Blade file holds about seven sentences and the controller builds sixty.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Support\CobrowseConsentState;
use App\Support\CobrowseReplayPreview;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\CobrowseSnapshotFreshness;
use App\Support\CobrowseTransportPressure;
use App\Support\DashboardLanguage;
use App\Support\ExternalIssueSyncStatus;
use App\Support\TicketExternalIssueAttempt;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

uses(RefreshDatabase::class);

/**
 * Tickets in the states the queue's CONDITIONAL rows need.
 *
 * Reply visibility renders only when the latest message is an agent reply; the
 * escalation cue only after a recent escalation; the lifecycle note only when a
 * lifecycle event carries one. None of those appear on an ordinary fixture, so
 * their copy stayed English through a whole review round -- the guard was
 * looking at pages that never rendered them.
 *
 * @param  array{account: Account, site: Site, agents: array<string, User>}  $world
 */
function conversationQueueLanguageTicketStates(array $world, Conversation $conversation): void
{
    $agent = $world['agents']['de'];

    $escalated = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create([
            'category' => 'task',
            'priority' => 'normal',
            'status' => 'open',
            'subject' => 'Datenpunkt replied',
            'description' => 'Datenpunkt body',
        ]);

    // An agent reply last, which is what makes reply visibility render.
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Datenpunkt agent reply',
    ]);

    // A recent escalation aimed at this agent, which also carries the note the
    // lifecycle row reads.
    AuditEvent::query()->create([
        'account_id' => $world['account']->id,
        'actor_type' => $agent->getMorphClass(),
        'actor_id' => $agent->id,
        'subject_type' => $escalated->getMorphClass(),
        'subject_id' => $escalated->id,
        'action' => 'ticket.escalated',
        // `reason` is the key the escalation note is read from; `note` is not.
        'metadata' => ['target_agent_id' => $agent->id, 'reason' => 'Datenpunkt escalation reason'],
        'occurred_at' => now(),
    ]);

    // An external link, which is what makes the attempt row render at all.
    // Through the factory rather than hand-built: the table has four NOT NULL
    // columns a literal insert discovers one failure at a time.
    TicketExternalLink::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($escalated)
        ->create([
            'provider' => 'github',
            'project_key' => 'Datenpunkt/repo',
            'sync_status' => ExternalIssueSyncStatus::FAILED,
            'last_synced_at' => now(),
        ]);
}

/**
 * Named for this file rather than for the concept. Pest helpers are global, and
 * a name like `queueWorld()` collides with whatever the next file wants.
 *
 * @return array{account: Account, site: Site, agents: array<string, User>}
 */
function conversationQueueLanguageWorld(int $conversations = 3): array
{
    // Support codes are unique account-wide, so a test that builds two worlds
    // -- one to check a singular and one a plural -- collides without this.
    static $run = 0;
    $run++;

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
            'support_code' => 'WF-LANG'.$run.$i,
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
 * The text the page shows, with markup and scripts removed.
 */
function conversationQueueLanguageVisibleText(string $html): string
{
    if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $main) === 1) {
        $html = $main[1];
    }

    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;

    return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)));
}

/**
 * The page's sentences, reduced to the parts a comparison can honestly judge.
 *
 * Three things defeated the first version of this, and each one hid real
 * untranslated copy that mutation testing then found:
 *
 * 1. **Rows are one line of several fields.** `strip_tags` collapses a row into
 *    `· Latest visitor message · Activity 2 minutes ago · <the message body>`,
 *    so a line-level comparison judges copy and data together. Split on the
 *    separator and each field is judged on its own.
 * 2. **Data was rejected by the LINE.** Dropping any line containing the
 *    account name threw away the copy sitting next to it. Segments containing
 *    data are dropped now, and the copy beside them survives.
 * 3. **An interpolated time differs between languages even when the copy does
 *    not.** `Opened 2 minutes ago` against `Opened vor 2 Minuten` are not
 *    equal, so an untranslated `Opened` passes a comparison test forever.
 *    Segments carrying a number are set aside here and asserted directly in
 *    `the row copy an elapsed time hides`.
 *
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
    $text = html_entity_decode(strip_tags($html));

    return collect(preg_split('/[\r\n]+/u', $text) ?: [])
        ->flatMap(fn (string $line): array => preg_split('/[·|]/u', $line) ?: [])
        ->map(fn (string $segment): string => trim(preg_replace('/\s+/u', ' ', $segment) ?? ''))
        // Data rather than copy, and correctly identical in both languages.
        ->reject(fn (string $segment): bool => str_contains($segment, 'Datenpunkt')
            || str_contains($segment, 'WF-LANG')
            || str_contains($segment, 'anon-')
            || str_contains($segment, '@'))
        // Carries an interpolated value that is itself localised, so the
        // segment differs between languages whether or not its copy does.
        ->reject(fn (string $segment): bool => preg_match('/\d/', $segment) === 1)
        // Long enough to be copy rather than a fragment of markup.
        ->filter(fn (string $segment): bool => mb_strlen($segment) >= 10)
        ->unique()
        ->values()
        ->all();
}

/**
 * The one recorded exception, matched EXACTLY.
 *
 * `CobrowseConsentState` supplies the transport label on every row, and its
 * vocabulary is shared with the conversation detail page -- about a hundred and
 * thirty strings that extract with cobrowse rather than from a queue-shaped
 * change reaching into them. Until then a German agent reads that one cell in
 * English, which is recorded in docs/product/dashboard-language.md.
 *
 * Exact strings rather than a substring test, because the last allowlist on
 * this epic exempted `mail` and quietly matched every sentence containing
 * "email". And `the recorded exception is still real` fails when an entry here
 * stops appearing, so an exemption cannot outlive the thing it excuses.
 *
 * @return array<int, string>
 */
function conversationQueueLanguageExceptions(): array
{
    return ['Unavailable'];
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

        $shared = array_values(array_diff(array_intersect($inEnglish, $inGerman), conversationQueueLanguageExceptions()));

        expect($shared)->toBe([], "untranslated copy in state: {$label}");
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

test('a sentence agrees with its own count, not just the number inside it', function (): void {
    // `:shown` chose correctly between "1 conversation" and "3 conversations"
    // and the sentence around it did not, so German read
    // "Es werden 1 Unterhaltung angezeigt, die ... entsprechen" -- two plural
    // verbs about one conversation. English had the same class of error in the
    // lane heading: "1 shown of 1 matching conversations".
    $one = conversationQueueLanguageWorld(conversations: 1);

    $inGerman = conversationQueueLanguageVisibleText(
        $this->actingAs($one['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($inGerman)->toContain('Es wird 1 Unterhaltung angezeigt, die den aktuellen Filtern entspricht.')
        ->and($inGerman)->not->toContain('Es werden 1 Unterhaltung')
        ->and($inGerman)->not->toContain('entsprechen.');

    // And the plural still inflects the other way, so this is measuring
    // agreement rather than a sentence rewritten to dodge it.
    $many = conversationQueueLanguageWorld(conversations: 3);

    $manyInGerman = conversationQueueLanguageVisibleText(
        $this->actingAs($many['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($manyInGerman)->toContain('Es werden 3 Unterhaltungen angezeigt, die den aktuellen Filtern entsprechen.')
        ->and($manyInGerman)->not->toContain('Es wird 3 Unterhaltungen');
});

test('the lane heading counts one match as one, in both languages', function (): void {
    // Reachable only when a support lane narrows the queue below what the other
    // filters match, which no default render produces.
    $world = conversationQueueLanguageWorld(conversations: 1);

    $url = route('dashboard.conversations.index', ['conversation_filter' => 'assigned_to_me']);

    $english = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['en'])->get($url)->getContent()
    );
    $german = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get($url)->getContent()
    );

    // Whatever else is on the page, neither language may say "1 ... matching
    // conversations" or its German equivalent.
    expect($english)->not->toContain('1 matching conversations')
        ->and($german)->not->toContain('1 passende Unterhaltungen')
        ->and($german)->not->toContain('1 passenden Unterhaltungen');
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

test('the recorded exception is still real', function (): void {
    // An allowlist nobody rechecks becomes a place where real misses hide. If
    // cobrowse gets extracted, or that label stops rendering, this fails and
    // the exemption has to be removed rather than quietly covering something
    // it was never meant to.
    $world = conversationQueueLanguageWorld();

    $rendered = conversationQueueLanguageSentences(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    foreach (conversationQueueLanguageExceptions() as $exception) {
        expect($rendered)->toContain($exception);
    }
});

test('the row copy an elapsed time hides is translated too', function (): void {
    // What the comparison test cannot reach, asserted directly.
    //
    // A row's copy shares its line with an interpolated value -- an elapsed
    // time Carbon localises on its own, a message body, an agent name -- so
    // `Opened 2 minutes ago` and `Opened vor 2 Minuten` differ whether or not
    // `Opened` was ever translated. Every one of these survived a mutation back
    // to an English literal while the comparison test stayed green.
    $world = conversationQueueLanguageWorld();

    // Against the text the page SHOWS, not the raw response. `assertDontSee`
    // reads the whole document including `<script>`, and the app layout carries
    // a JavaScript comment containing the word "Opened" -- which failed this
    // test for a sentence no agent can read.
    $german = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    foreach ([
        'Geöffnet',
        'Wartet seit',
        'Letzte Besuchernachricht',
        'Letzte Agentenantwort',
        'Noch keine Nachrichten',
        'Wartet auf Besucher',
        'Letzte Meldung',
        'Aktivität',
    ] as $expected) {
        expect($german)->toContain($expected);
    }

    foreach ([
        'Opened ',
        'Waiting on reply for',
        'Waiting on visitor for',
        'Latest visitor message',
        'Latest agent reply',
        'No messages yet',
        'Last report',
        'Activity ',
    ] as $english) {
        expect($german)->not->toContain($english);
    }

    // And the English page still reads as English, so this is measuring
    // translation rather than measuring that the strings moved.
    $inEnglish = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['en'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($inEnglish)->toContain('Latest visitor message')
        ->and($inEnglish)->toContain('Waiting on reply for')
        ->and($inEnglish)->not->toContain('Letzte Besuchernachricht');
});

test('every column header is translated, read from the header row itself', function (): void {
    // The comparison test cannot judge these: every header is shorter than its
    // 10-character floor, and lowering that would sweep in names, numbers and
    // markup fragments.
    //
    // Read from `<th>` rather than from the page text, which is the whole
    // point. Asserting that the German page merely CONTAINS `Besucher` passes
    // while the header still says `Visitor`, because the word appears in the
    // search hint, in a lane label and in the visitor column -- a mutation of
    // that header survived exactly that assertion.
    $world = conversationQueueLanguageWorld();

    $headersOf = function (User $agent): array {
        $html = (string) $this->actingAs($agent)
            ->get(route('dashboard.conversations.index'))
            ->getContent();

        preg_match_all('/<th\b[^>]*scope="col"[^>]*>(.*?)<\/th>/is', $html, $matches);

        return array_map(fn (string $header): string => trim(strip_tags($header)), $matches[1]);
    };

    $inEnglish = $headersOf($world['agents']['en']);
    $inGerman = $headersOf($world['agents']['de']);

    expect($inEnglish)->not->toBe([])
        ->and($inGerman)->toHaveCount(count($inEnglish));

    foreach ($inEnglish as $index => $header) {
        // `Cobrowse` is the product's own word and reads the same in both
        // languages, correctly.
        if ($header === 'Cobrowse') {
            continue;
        }

        expect($inGerman[$index])->not->toBe($header, "column header not translated: {$header}");
    }
});

test('the shared support-code control speaks the surface it is rendered on', function (): void {
    // A shared Blade component, unlike a shared model, may use the catalogue
    // directly: a view is only rendered inside a request, and the locale is
    // scoped per request to surfaces that have been extracted. So the same
    // component renders German here and English on the ticket queue beside it,
    // which is right while the extraction is half done.
    //
    // The comparison test cannot see any of this: `Copy` is under its length
    // floor and the rest are attributes, which `strip_tags` discards.
    $world = conversationQueueLanguageWorld();

    $german = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    expect($german)->toContain('>Kopieren</button>')
        ->and($german)->toContain('Support-Code kopieren')
        ->and($german)->toContain('öffnen')
        ->and($german)->not->toContain('>Copy</button>')
        ->and($german)->not->toContain('Open support record');

    // The conversation DETAIL page is not extracted, and renders the same
    // component -- so it is English there, for the same German agent, in the
    // same session. That is the property that makes translating a shared view
    // safe, and it is only observable on a page that actually renders one.
    $conversation = Conversation::query()->orderByDesc('id')->firstOrFail();

    $detail = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    expect($detail)->toContain('>Copy</button>')
        ->and($detail)->not->toContain('>Kopieren</button>');
});

test('the queue claims to be translated, so a screen reader is told the truth', function (): void {
    // The layout marks a page English until its surface says otherwise, so an
    // extracted surface that forgets to claim it is announced as English while
    // reading German -- the same wrong answer as the default, in the other
    // direction.
    $world = conversationQueueLanguageWorld();

    // One language for the whole document, since the shell was extracted: the
    // rail, the crumb and the page are all the agent's language now. While the
    // shell was English this needed a root saying `en`, a `<main>` saying `de`
    // and a crumb of its own -- all three collapsed when the rail learned to
    // speak.
    $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('<span class="wf-crumb-current">Unterhaltungen</span>', false)
        ->assertDontSee('<main class="page" lang', false);

    $this->actingAs($world['agents']['en'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('<span class="wf-crumb-current">Conversations</span>', false);
});

test('translating a model would put German on pages that are still English', function (): void {
    // The regression this guards is subtle and was real: `attentionLabel()` and
    // `presenceLabel()` live on models, and a model is read by every surface
    // that touches it. A `__()` there put `Antwort nötig` inside the
    // conversation detail page -- which is not extracted, and correctly
    // declares `<html lang="en">`. That is exactly the mixed-language problem
    // the per-surface flag exists to prevent, arriving through the model rather
    // than through the layout.
    //
    // So models answer with STATE and extracted surfaces translate at their own
    // call site. This asserts the unextracted page stays English for an agent
    // who reads German, which is the correct answer until it is extracted.
    $world = conversationQueueLanguageWorld();

    // The conversation with an AGENT message last, which is the state whose
    // label this test is about. Looked up by that rather than by a support code
    // the world now numbers per run.
    $conversation = Conversation::query()
        ->whereHas('messages', fn ($query) => $query->where('sender_type', User::class))
        ->orderByDesc('id')
        ->firstOrFail();

    $detail = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent()
    );

    expect($detail)->toContain('Waiting on visitor')
        ->and($detail)->not->toContain('Wartet auf Besucher');

    // And the queue, which IS extracted, still says it in German -- so this is
    // measuring where the translation happens rather than that it stopped.
    $queue = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($queue)->toContain('Wartet auf Besucher');
});

test('the form labels and the untitled fallback are translated', function (): void {
    // All four are under the comparison test's length floor, or sit on a line
    // with the conversation's own data. `Untitled conversation` is a normal
    // production path -- the widget stores a null subject when the visitor
    // does not give one -- rather than an edge case.
    $world = conversationQueueLanguageWorld(conversations: 0);

    Conversation::factory()
        ->for($world['site'])
        ->for(Visitor::factory()->for($world['site'])->create(['anonymous_id' => 'anon-nosubject']))
        ->create(['support_code' => 'WF-LANGNOSUBJ', 'subject' => null, 'status' => 'open']);

    $german = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($german)->toContain('Unterhaltung ohne Betreff')
        ->and($german)->not->toContain('Untitled conversation');

    // The three filter labels are read from the `<label>` elements themselves,
    // not from the page text. Asserting the German page merely CONTAINS
    // `Suche`, `Website` or `Status` passes while every label is still
    // English -- all three words appear elsewhere on the page, in the search
    // hint, the column header and the presence option. Three mutations
    // survived exactly that before this was rewritten.
    $labelsOf = function (User $agent): array {
        $html = (string) $this->actingAs($agent)
            ->get(route('dashboard.conversations.index'))
            ->getContent();

        preg_match_all('/<label for="conversation_[^"]+"[^>]*>(.*?)<\/label>/is', $html, $matches);

        return array_map(fn (string $label): string => trim(strip_tags($label)), $matches[1]);
    };

    $englishLabels = $labelsOf($world['agents']['en']);
    $germanLabels = $labelsOf($world['agents']['de']);

    expect($englishLabels)->toBe(['Search', 'Site', 'Presence'])
        ->and($germanLabels)->toBe(['Suche', 'Website', 'Status']);

    $english = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['en'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($english)->toContain('Untitled conversation')
        ->and($english)->not->toContain('Unterhaltung ohne Betreff');
});

test('the attention lane heading is a sentence, not a clause in a number slot', function (): void {
    // Reachable only when the lane shows FEWER than the other filters match, so
    // the world marks two of three conversations as already read: one still
    // needs attention, three still match. No default render produces this.
    $world = conversationQueueLanguageWorld(conversations: 3);

    foreach (['de', 'en'] as $locale) {
        $agent = $world['agents'][$locale];

        Conversation::query()->orderBy('id')->take(2)->get()
            ->each(fn (Conversation $conversation) => $conversation->readStates()->updateOrCreate(
                ['user_id' => $agent->id],
                ['last_read_at' => now()->addDay()],
            ));
    }

    $url = route('dashboard.conversations.index', ['conversation_filter' => 'new_activity']);

    $german = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get($url)->assertOk()->getContent()
    );
    $english = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['en'])->get($url)->assertOk()->getContent()
    );

    // The shape that was broken: a whole clause dropped into the slot the other
    // lanes fill with a number.
    expect($german)->not->toContain('benötigt Aufmerksamkeit von')
        ->and($german)->not->toContain('benötigen Aufmerksamkeit von')
        ->and($english)->not->toContain('needs attention shown of');

    expect($german)->toContain('1 von 3 passenden Unterhaltungen benötigt Aufmerksamkeit')
        ->and($english)->toContain('1 of 3 matching conversations needs attention')
        // Dative, not nominative: German inflects the adjective for case too,
        // and every sentence using this count reads "von :matching".
        ->and($german)->not->toContain('passende Unterhaltungen');
});

test('a visitor with nothing to be named by is still named in German', function (): void {
    // Every column the label falls back through is nullable -- name, email and
    // anonymous_id -- so this is a real row rather than a defensive branch.
    // A conversation can also carry no visitor at all.
    $world = conversationQueueLanguageWorld(conversations: 0);

    Conversation::factory()
        ->for($world['site'])
        ->for(Visitor::factory()->for($world['site'])->create([
            'name' => null,
            'email' => null,
            'anonymous_id' => null,
        ]))
        ->create(['support_code' => 'WF-LANGANON', 'subject' => 'Datenpunkt anon', 'status' => 'open']);

    $german = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($german)->toContain('Unbekannter Besucher')
        ->and($german)->not->toContain('Unknown visitor');

    $english = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['en'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($english)->toContain('Unknown visitor')
        ->and($english)->not->toContain('Unbekannter Besucher');
});

test('zero takes the plural, which no explicit rule in the catalogue says', function (): void {
    // `{1} …|[2,*] …` covers one and many. Zero matches neither, and Laravel
    // falls through to the locale's own plural rule -- which puts it in the
    // plural for both English and German. That is correct, and it is correct by
    // a path nothing in the catalogue states, so it is worth pinning: someone
    // adding an explicit `{0}` later should have to notice this.
    //
    // An empty filtered queue is a normal state, not an edge case.
    $world = conversationQueueLanguageWorld(conversations: 2);

    $url = route('dashboard.conversations.index', ['conversation_search' => 'zzzz-nothing-matches']);

    $german = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get($url)->assertOk()->getContent()
    );
    $english = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['en'])->get($url)->assertOk()->getContent()
    );

    expect($german)->toContain('Es werden 0 Unterhaltungen angezeigt')
        ->and($german)->not->toContain('Es wird 0 Unterhaltung ')
        ->and($english)->toContain('Showing 0 conversations')
        ->and($english)->not->toContain('0 conversation matching');
});

test('the cobrowse exception says it is English, value and all', function (): void {
    // Every string CobrowseConsentState supplies is still English, and it is
    // rendered inside a region marked with the agent's language -- so without
    // saying so, a screen reader pronounces the one deliberately untranslated
    // cell on the page with German phonetics. Same rule the profile page's
    // exception follows.
    //
    // The awkward half is the two mixed sentences: a German label wrapping an
    // English value. Splitting them to wrap the value would be the fragment
    // concatenation this extraction refuses, so the marked value goes in as the
    // placeholder instead.
    $world = conversationQueueLanguageWorld();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    // The wholly-English cell: label, and the message/guidance in its title.
    expect($html)->toContain('class="wf-queue-cobrowse"')
        ->and($html)->toMatch('/<span\s+class="wf-queue-cobrowse"\s+lang="en"/');

    // And the English value inside an otherwise German sentence.
    expect($html)->toContain('Letzte Meldung <span lang="en">Not reported</span>');

    // An English agent gets the same markup, because the exception is about
    // the copy's language rather than about the reader's.
    $inEnglish = (string) $this->actingAs($world['agents']['en'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    expect($inEnglish)->toContain('Last report <span lang="en">Not reported</span>');
});

test('a localised cobrowse timestamp is marked German, not English', function (): void {
    // `last_report` is the static "Not reported" ONLY in the `unavailable`
    // state. Every other state builds it with `diffForHumans()`, which follows
    // the page's locale -- so on this route it is already German. Marking that
    // English has a screen reader pronounce German as English, which is the
    // same defect as leaving it unmarked, pointing the other way.
    //
    // Decided from the state rather than by reading the prose.
    $world = conversationQueueLanguageWorld();

    $this->instance(CobrowseConsentState::class, new class(app(CobrowseReplayPreview::class), app(CobrowseResyncRequestPolicy::class), app(CobrowseSnapshotFreshness::class), app(CobrowseTransportPressure::class)) extends CobrowseConsentState
    {
        public function queueTransportForConversation(Conversation $conversation): array
        {
            return [
                'state' => 'live',
                'label' => 'Live',
                'message' => 'x',
                // What `diffForHumans()` returns once the locale is German.
                'last_report' => 'vor 20 Sekunden',
                'pressure' => '2 dropped batches',
                'guidance' => 'x',
                'recovery_action' => 'x',
                'tone' => 'ready',
            ];
        }
    });

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Letzte Meldung <span lang="de">vor 20 Sekunden</span>')
        ->and($html)->not->toContain('<span lang="en">vor 20 Sekunden</span>')
        // The pressure value beside it IS static English -- English words and
        // an English pluraliser -- so it stays marked English.
        ->and($html)->toContain('<span lang="en">2 dropped batches</span>');
});

test('a cobrowse value is escaped, not trusted', function (): void {
    // Marking the value meant rendering that sentence unescaped, so only the
    // catalogue string is trusted and the value is escaped on the way in. That
    // is a claim about safety, so it gets a test rather than a comment.
    $world = conversationQueueLanguageWorld();

    // Resolved from the container so its four collaborators come along; only
    // the one method under test is replaced.
    $this->instance(CobrowseConsentState::class, new class(app(CobrowseReplayPreview::class), app(CobrowseResyncRequestPolicy::class), app(CobrowseSnapshotFreshness::class), app(CobrowseTransportPressure::class)) extends CobrowseConsentState
    {
        public function queueTransportForConversation(Conversation $conversation): array
        {
            return [
                'state' => 'unavailable',
                'label' => 'Unavailable',
                'message' => 'x',
                'last_report' => '<script>alert(1)</script>',
                'pressure' => 'No drops reported',
                'guidance' => 'x',
                'recovery_action' => 'x',
                'tone' => 'manual',
            ];
        }
    });

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

/**
 * Every readable string on a page, with the language it is actually announced in.
 *
 * Text nodes AND the attributes a screen reader reads -- `title`, `aria-label`,
 * `placeholder`, `alt` -- because a third of this page's copy lives in
 * attributes and `strip_tags` throws all of it away before any comparison sees
 * it. Each string is paired with its EFFECTIVE language: the nearest ancestor
 * carrying `lang`, which is what assistive technology resolves.
 *
 * Returned as a LIST of occurrences rather than a map keyed by text, and that
 * matters: a queue renders the same string once per row. Keyed by text, a later
 * occurrence overwrites an earlier one's language -- so one row leaking
 * `Unavailable` as German is masked the moment a later row marks the same word
 * English, and the guard passes. Attributes processed after text nodes overwrite
 * them the same way.
 *
 * @return array<int, array{text: string, language: string}>
 */
function conversationQueueLanguageAnnouncements(string $html): array
{
    // The WHOLE document, not just `<main>`. The shell is an extracted surface
    // now -- the rail, the topbar and the search all speak the agent's language
    // -- and a guard that stops at `<main>` would never have looked at any of
    // it. It was scoped to the page region back when the shell was English by
    // design and would have been reported as a leak on every page.
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"?><div lang="de">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $languageOf = function (?DOMNode $element): string {
        for (; $element instanceof DOMElement; $element = $element->parentNode) {
            if ($element->hasAttribute('lang')) {
                return $element->getAttribute('lang');
            }
        }

        return 'de';
    };

    $announcements = [];
    $xpath = new DOMXPath($document);

    foreach ($xpath->query('//text()') ?: [] as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        if ($text !== '') {
            $announcements[] = ['text' => $text, 'language' => $languageOf($node->parentNode)];
        }
    }

    foreach (['title', 'aria-label', 'placeholder', 'alt'] as $attribute) {
        foreach ($xpath->query('//*[@'.$attribute.']') ?: [] as $element) {
            $text = trim(preg_replace('/\s+/u', ' ', $element->getAttribute($attribute)) ?? '');

            if ($text !== '') {
                $announcements[] = ['text' => $text, 'language' => $languageOf($element)];
            }
        }
    }

    return $announcements;
}

/**
 * Strings that are correctly identical in both languages.
 *
 * EXACT matches, never substrings: the last allowlist on this epic exempted
 * `mail` and silently matched every sentence containing "email", hiding three
 * real misses. And `every cognate on the list still appears` fails when an entry
 * stops matching, so an exemption cannot outlive the thing it excuses.
 *
 * @return array<string, string> string => why it is the same in both
 */
function conversationQueueLanguageCognates(): array
{
    return [
        'Name' => 'the same word in both languages',
        'Agent' => 'the same word in both languages',
        'Cobrowse' => "the product's own name for the feature, not translated",
        'Wayfindr' => 'the product name, which is not copy',
        'Tickets' => 'the same word in both languages',
        'Status' => 'the same word in both languages',
        'Normal' => 'the same word in both languages, as a priority',
        'Label' => 'a loanword German uses as-is',
        'Labels' => 'a loanword German uses as-is',
        'English' => 'an autonym -- the language selector names each language in its own language',
        'Deutsch' => 'an autonym -- see above',
    ];
}

/**
 * Strings a German page announces AS German that did not change when the
 * language did -- so they were never translated.
 *
 * This replaced a function-word heuristic, which could not see `Unavailable`
 * (no English function word in it) and could not see attributes at all. Both
 * were real leaks. Comparing the two renders catches any untranslated string
 * regardless of its shape, and reading the effective `lang` means a recorded
 * exception that declares itself English is correctly ignored rather than
 * allow-listed by name.
 *
 * **What it still cannot see, stated rather than assumed:** an untranslated
 * fragment INTERPOLATED into a translated sentence. `Letzte Meldung Not
 * reported` differs from `Last report Not reported`, so the comparison passes
 * while `Not reported` is English in both. Verified by mutation -- unwrapping
 * that value is the one leak shape of seven that this guard misses.
 *
 * The mitigation is the rule rather than the net: an untranslated value
 * interpolated into a translated sentence must be marked at the point of
 * interpolation, which `the cobrowse exception says it is English, value and
 * all` tests directly and three mutations hold in place. When a guard cannot
 * reach a class of mistake, assert that class directly -- do not widen the
 * guard until it produces noise.
 *
 * @return array<int, string>
 */
function conversationQueueLanguageEnglishLeaks(string $germanHtml, string $englishHtml): array
{
    $german = conversationQueueLanguageAnnouncements($germanHtml);

    // Only a SET is needed from the English side -- the question there is
    // "does this string appear at all", not where or in what language.
    $english = array_flip(array_column(conversationQueueLanguageAnnouncements($englishHtml), 'text'));

    $isData = fn (string $text): bool => str_contains($text, 'Datenpunkt')
        || str_contains($text, 'WF-LANG')
        || str_contains($text, 'anon-')
        || str_contains($text, '@')
        // Numbers, punctuation and single letters read the same in both
        // languages, correctly.
        || preg_match('/\p{L}{3}/u', $text) !== 1;

    $leaks = [];

    $cognates = conversationQueueLanguageCognates();

    foreach ($german as ['text' => $text, 'language' => $language]) {
        if ($language !== 'de' || $isData($text) || ! array_key_exists($text, $english)) {
            continue;
        }

        if (array_key_exists($text, $cognates)) {
            continue;
        }

        $leaks[] = $text;
    }

    return array_values(array_unique($leaks));
}

test('no English is rendered as German on any extracted surface', function (): void {
    // The guard the last several review rounds were doing by hand. Every one of
    // them was the same shape -- copy reaching an extracted page from somewhere
    // that is not that page: a model, a shared component, a support class, a
    // nullable fallback. Each was found individually and none of them told me
    // where the next one was.
    //
    // Driven by EXTRACTED_ROUTES, so a surface added later is covered the day
    // it is added rather than the day someone reads it aloud.
    $world = conversationQueueLanguageWorld();
    $agent = $world['agents']['de'];

    // Tickets, so the ticket queue is audited with rows rather than empty --
    // an empty page passes any completeness check trivially.
    $conversation = Conversation::query()->firstOrFail();

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'billing', 'priority' => 'high', 'status' => 'open', 'subject' => 'Datenpunkt refund', 'description' => 'Datenpunkt description one']);

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['category' => 'bug', 'priority' => 'low', 'status' => 'closed', 'subject' => 'Datenpunkt defect', 'description' => 'Datenpunkt description two']);

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt bare', 'description' => null]);

    conversationQueueLanguageTicketStates($world, $conversation);

    $states = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.conversations.index', ['conversation_filter' => 'closed']),
        route('dashboard.conversations.index', ['conversation_filter' => 'assigned_to_me']),
        route('dashboard.conversations.index', ['conversation_search' => 'zzzz']),
        route('dashboard.tickets.index'),
        route('dashboard.tickets.index', ['ticket_status' => 'closed']),
        route('dashboard.tickets.index', ['ticket_status' => 'all']),
        route('dashboard.tickets.index', ['ticket_attention' => 'needs_owner']),
        route('dashboard.tickets.index', ['ticket_external' => 'none']),
        route('dashboard.tickets.index', ['ticket_search' => 'zzzz']),
        // A refinement that matches nothing, which is a DIFFERENT empty state
        // from the search one and carries its own message.
        route('dashboard.tickets.index', ['ticket_priority' => 'urgent']),
    ];

    // Every GET-able extracted route is covered, whether or not it is listed
    // above -- so this fails loudly when a surface is extracted without being
    // added here, rather than silently skipping it.
    $covered = collect($states)->map(fn (string $url): string => parse_url($url, PHP_URL_PATH))->all();

    foreach (DashboardLanguage::EXTRACTED_ROUTES as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        if ($route === null || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
            continue;
        }

        // Not `expect()->toContain()`: that is variadic, so a message passed
        // as a second argument becomes a second required value and the failure
        // reports the message itself as missing.
        $this->assertContains('/'.ltrim($route->uri(), '/'), $covered, "extracted route not audited: {$name}");
    }

    foreach ($states as $url) {
        $leaks = conversationQueueLanguageEnglishLeaks(
            (string) $this->actingAs($agent)->get($url)->assertOk()->getContent(),
            (string) $this->actingAs($world['agents']['en'])->get($url)->assertOk()->getContent(),
        );

        expect($leaks)->toBe([], "announced as German but never translated, at {$url}");
    }

    // The shell's search help renders only after a support-code lookup has
    // flashed a status, which no ordinary page state produces -- so a mutation
    // of that copy survived every other state in this test. A guard is only as
    // good as the states it visits.
    // Carries the world's data token, because the flashed value itself is test
    // DATA -- identical in both renders, correctly -- and would otherwise be
    // reported as the leak.
    $flashed = ['support_code_lookup_status' => 'Datenpunkt lookup found nothing'];

    $leaks = conversationQueueLanguageEnglishLeaks(
        (string) $this->actingAs($agent)->withSession($flashed)->get(route('dashboard.conversations.index'))->assertOk()->getContent(),
        (string) $this->actingAs($world['agents']['en'])->withSession($flashed)->get(route('dashboard.conversations.index'))->assertOk()->getContent(),
    );

    expect($leaks)->toBe([], 'announced as German but never translated, in the support-lookup empty state');
});

test('a filter chip translates its label, not only the value it wraps', function (): void {
    // The comparison guard cannot see these: a chip is `Kategorie: Fehler`
    // against `Category: Bug`, so the string differs whether or not the LABEL
    // was translated. The value carries the whole comparison with it.
    //
    // Same blind spot as the cobrowse `Letzte Meldung` case, and the same
    // answer: assert the class directly.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'bug', 'priority' => 'high', 'status' => 'open', 'subject' => 'Datenpunkt chip', 'description' => 'Datenpunkt body']);

    $url = route('dashboard.tickets.index', [
        'ticket_category' => 'bug',
        'ticket_priority' => 'high',
        'ticket_filter' => 'unassigned',
        'ticket_search' => 'Datenpunkt',
    ]);

    $german = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])->get($url)->assertOk()->getContent()
    );

    // Only the prefixes that actually differ in German. `Status:` and `Label:`
    // are the same word in both and are deliberately absent.
    foreach (['Kategorie:', 'Priorität:', 'Zuweisung:', 'Suche:'] as $prefix) {
        expect($german)->toContain($prefix);
    }

    foreach (['Category:', 'Priority:', 'Assignee:', 'Search:'] as $english) {
        expect($german)->not->toContain($english);
    }
});

test('every cognate on the list still appears, so the list cannot rot', function (): void {
    // An allowlist nobody rechecks becomes a place real misses hide. If one of
    // these stops rendering, or gets translated after all, this fails and the
    // entry has to go rather than quietly covering something else.
    $world = conversationQueueLanguageWorld();

    // Tickets too, since several cognates only appear on that queue.
    $conversation = Conversation::query()->firstOrFail();

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'billing', 'priority' => 'normal', 'status' => 'open', 'subject' => 'Datenpunkt cognate', 'description' => 'Datenpunkt body']);

    $announced = array_column(array_merge(
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['agents']['de'])->get(route('dashboard.profile.show'))->getContent()
        ),
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
        ),
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['agents']['de'])->get(route('dashboard.tickets.index'))->getContent()
        ),
    ), 'text');

    foreach (array_keys(conversationQueueLanguageCognates()) as $cognate) {
        expect($announced)->toContain($cognate);
    }
});

test('a marked occurrence does not mask an unmarked one', function (): void {
    // The guard is a test, and a test that cannot fail is worse than none. This
    // exercises its own logic on crafted markup rather than on a page, because
    // the case only arises when the SAME string appears twice in different
    // languages -- which a real queue produces one row at a time and no single
    // fixture reliably reproduces.
    //
    // Keyed by text, the second occurrence overwrote the first and the leak
    // vanished. Occurrences are kept separately now.
    $german = <<<'HTML'
    <main lang="de">
        <p>Unavailable</p>
        <p><span lang="en">Unavailable</span></p>
    </main>
    HTML;

    $english = '<main lang="en"><p>Unavailable</p><p>Unavailable</p></main>';

    expect(conversationQueueLanguageEnglishLeaks($german, $english))->toBe(['Unavailable']);

    // Marked everywhere, nothing to report.
    $allMarked = '<main lang="de"><p><span lang="en">Unavailable</span></p><p><span lang="en">Unavailable</span></p></main>';

    expect(conversationQueueLanguageEnglishLeaks($allMarked, $english))->toBe([]);

    // And an attribute cannot mask a text node either -- the same bug by a
    // different route, since attributes are collected after text nodes. Both
    // halves fall to one mutation (keying the collection by text), because that
    // is the single change that reintroduces the masking; keying only the
    // attribute half does nothing, as the two then occupy different key spaces.
    $attributeAfter = '<main lang="de"><p>Unavailable</p><button lang="en" title="Unavailable">x</button></main>';

    expect(conversationQueueLanguageEnglishLeaks($attributeAfter, $english))->toBe(['Unavailable']);
});

test('a model hands out keys, so an agent gets their own language and not a process one', function (): void {
    // The sharpest case for why models do not translate: readStateKeyFor()
    // takes an AGENT. Translated inside the model it would answer in whatever
    // locale the process last set -- so a job or a mail build would hand an
    // English agent German because a German agent's request ran first.
    //
    // This simulates exactly that: set the ambient locale to German with no
    // request scoping it, then ask the model about an English agent.
    $world = conversationQueueLanguageWorld(conversations: 1);
    $conversation = Conversation::query()->firstOrFail();

    App::setLocale('de');

    // Keys and data, never sentences -- so nothing here can carry the wrong
    // language out of the model.
    expect($conversation->readStateKeyFor($world['agents']['en']))->toBe('read_new_activity')
        ->and($conversation->queueActivityPreview()['label_key'])->toBe('preview_none_label')
        ->and($conversation->queueTimingContext()['wait_key'])->toBe('no_messages')
        ->and($conversation->queueTimingContext()['opened_at'])->toBeInstanceOf(CarbonInterface::class)
        ->and($conversation->attentionState())->toBe('needs_reply');

    // And the English labels a surface that has NOT been extracted still reads
    // stay English, whatever the ambient locale is.
    expect($conversation->readStateLabelFor($world['agents']['en']))->toBe('New activity')
        ->and($conversation->attentionLabel())->toBe('Needs reply')
        ->and($conversation->queueActivityPreview()['label'])->toBe('No activity preview yet');
});

test('no raw catalogue key ever reaches the page', function (): void {
    // A missing key renders as `conversations.row.something` -- readable enough
    // to look like copy in a screenshot and wrong to everybody.
    //
    // This exists because two mutations survived without it. Turning a key back
    // into a translated STRING makes the view look up
    // `conversations.row.Letzte Besuchernachricht`, which misses and renders the
    // key itself -- and the key CONTAINS the German the assertions were looking
    // for, so `toContain` passed on a broken page. Substring assertions cannot
    // tell a sentence from a key that quotes it.
    $world = conversationQueueLanguageWorld();

    // Tickets, because two mutations survived this guard while its state list
    // still only knew about conversations -- a raw `tickets.row.…` key rendered
    // on a page the guard never opened.
    $conversation = Conversation::query()->firstOrFail();

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'billing', 'priority' => 'high', 'status' => 'open', 'subject' => 'Datenpunkt key', 'description' => 'Datenpunkt body']);

    // A ticket with nothing to preview: no messages and no description. Its
    // "no activity preview yet" branch renders on no other fixture, and a
    // mutation of that copy survived until this existed.
    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt bare', 'description' => null]);

    conversationQueueLanguageTicketStates($world, $conversation);

    $states = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.conversations.index', ['conversation_filter' => 'closed']),
        route('dashboard.conversations.index', ['conversation_filter' => 'new_activity']),
        route('dashboard.conversations.index', ['conversation_search' => 'zzzz']),
        route('dashboard.tickets.index'),
        route('dashboard.tickets.index', ['ticket_status' => 'closed']),
        route('dashboard.tickets.index', ['ticket_attention' => 'needs_owner']),
        route('dashboard.tickets.index', ['ticket_search' => 'zzzz']),
    ];

    foreach (['de', 'en'] as $locale) {
        foreach ($states as $url) {
            $text = conversationQueueLanguageVisibleText(
                (string) $this->actingAs($world['agents'][$locale])->get($url)->assertOk()->getContent()
            );

            // A KEY, not merely a catalogue name followed by a dot: an English
            // sentence ending "...for your profile." contains `profile.` and is
            // perfectly good copy. A key is the catalogue, a dot, and a
            // lowercase section -- no space between them.
            // Every catalogue, not the ones that existed when this was written:
            // two mutations survived because `tickets` was missing from here,
            // so a raw `tickets.row.…` key rendered unnoticed.
            foreach (['conversations', 'presence', 'support', 'profile', 'validation', 'tickets', 'nav'] as $catalogue) {
                $pattern = '/\b'.$catalogue.'\.[a-z][a-z_]*(\.[a-zA-Z_]+)*/';

                // A PHPUnit assertion rather than `expect()->not->toContain()`,
                // which is variadic: passing a message there asserts the text
                // contains neither the key NOR the message, and since it never
                // contains the message the negation passes on any page at all.
                // That is the second time this file has met that trap.
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $text,
                    "raw catalogue key from {$catalogue} rendered at {$url} in {$locale}"
                );
            }
        }
    }
});

test('the ticket queue heading names the status it is actually showing', function (): void {
    // A comparison guard cannot see this one: pinning the heading to the wrong
    // status still produces German, just the wrong German. `2 offen` where the
    // queue is showing closed tickets differs from the English `2 open` exactly
    // as a correct translation would, so the leak check passes.
    //
    // Copy can be wrong without being English -- the same class as the escaped
    // backslash, and it needs the same answer: assert the specific claim.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    foreach (['open', 'pending', 'closed'] as $status) {
        Ticket::factory()
            ->for($world['account'])
            ->for($world['site'])
            ->for($conversation)
            ->for($conversation->visitor, 'requester')
            ->create([
                'category' => 'task',
                'priority' => 'low',
                'status' => $status,
                'subject' => 'Datenpunkt '.$status,
                'description' => 'Datenpunkt body',
            ]);
    }

    foreach ([
        'open' => 'offen',
        'pending' => 'wartend',
        'closed' => 'geschlossen',
        'all' => 'insgesamt',
    ] as $filter => $expected) {
        $text = conversationQueueLanguageVisibleText(
            (string) $this->actingAs($world['agents']['de'])
                ->get(route('dashboard.tickets.index', ['ticket_status' => $filter]))
                ->assertOk()
                ->getContent()
        );

        // A message would make `toContain` variadic; the loop key is in the
        // failure line instead.
        $this->assertStringContainsString($expected, $text, "ticket queue heading for status: {$filter}");
    }
});

test('the ticket queue conditional rows are translated, labels and all', function (): void {
    // These sit in segments that also carry DATA -- an escalation reason, a
    // project key, a provider name -- so the comparison guard discards them
    // and a targeted assertion is the only thing that sees them. Same shape as
    // the filter chips: a translated label wrapping an untranslated value, or
    // the reverse.
    //
    // Every one of these stayed English through a full review round because no
    // fixture produced the branch that renders it.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    conversationQueueLanguageTicketStates($world, $conversation);

    $german = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.tickets.index'))
            ->assertOk()
            ->getContent()
    );

    foreach ([
        'Lebenszyklus-Notiz',      // the note label
        'Ticket eskaliert',        // the lifecycle event name
        'Letzter Versuch',         // the external attempt label
        'An Sie eskaliert',        // the escalation audience
        'Synchronisierung',        // the attempt's own label, from the support class
    ] as $expected) {
        $this->assertStringContainsString($expected, $german, "conditional ticket row copy: {$expected}");
    }

    foreach ([
        'Lifecycle note',
        'Ticket escalated',
        'Latest attempt',
        'Escalated to you',
        'sync failed',
    ] as $english) {
        $this->assertStringNotContainsString($english, $german, "English left on a German ticket row: {$english}");
    }

    // And the English page still reads as English, so this measures
    // translation rather than strings that simply moved.
    $inEnglish = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['en'])
            ->get(route('dashboard.tickets.index'))
            ->assertOk()
            ->getContent()
    );

    foreach (['Lifecycle note', 'Latest attempt', 'sync failed'] as $expected) {
        $this->assertStringContainsString($expected, $inEnglish, "English ticket row copy: {$expected}");
    }
});

test('every lifecycle action maps to a key that resolves', function (): void {
    // Five actions, and a page fixture can realistically render one of them.
    // Rather than build five tickets to reach five branches, this asserts the
    // mapping itself and that each key resolves in both languages -- a missing
    // one renders as `tickets.lifecycle.something` on the page.
    $world = conversationQueueLanguageWorld(conversations: 1);
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['de'];

    $actions = [
        'ticket.pending' => 'pending',
        'ticket.closed' => 'closed',
        'ticket.reopened' => 'reopened',
        'ticket.unheld' => 'unheld',
        'ticket.escalated' => 'escalated',
        'ticket.something_else' => 'default',
    ];

    foreach ($actions as $action => $expectedKey) {
        $ticket = Ticket::factory()
            ->for($world['account'])
            ->for($world['site'])
            ->for($conversation)
            ->for($conversation->visitor, 'requester')
            ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt '.$action, 'description' => 'Datenpunkt body']);

        AuditEvent::query()->create([
            'account_id' => $world['account']->id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->id,
            'action' => $action,
            'metadata' => ['reason' => 'r', 'pending_note' => 'r', 'resolution_note' => 'r', 'reopen_note' => 'r'],
            'occurred_at' => now(),
        ]);

        $note = Ticket::query()->find($ticket->id)->latestLifecycleNote();

        // The unknown action carries no note body, so it has nothing to render.
        if ($expectedKey === 'default') {
            expect($note)->toBeNull();

            continue;
        }

        expect($note['label_key'])->toBe($expectedKey);
    }

    // And every key in the map resolves in both languages, in both files.
    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);

        foreach (array_values($actions) as $key) {
            $resolved = trans('tickets.lifecycle.'.$key);

            expect($resolved)->not->toBe('tickets.lifecycle.'.$key);
        }
    }
});

test('adding a key never takes the English answer away', function (): void {
    // The rule this epic runs on: a model gains a KEY and keeps its English
    // label, because the surfaces that have not been extracted still read the
    // label. Setting `actor` to null and supplying only `actor_key` blanked the
    // actor on the ticket detail page -- which nothing in that change touched.
    $world = conversationQueueLanguageWorld(conversations: 1);
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['en'];

    // A visitor-authored event and an actor-less one: the two cases that have
    // no name to fall back to.
    foreach ([[Visitor::class, $conversation->visitor->id, 'Visitor'], [null, null, 'System']] as [$actorType, $actorId, $expected]) {
        $ticket = Ticket::factory()
            ->for($world['account'])
            ->for($world['site'])
            ->for($conversation)
            ->for($conversation->visitor, 'requester')
            ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt actor', 'description' => 'Datenpunkt body']);

        AuditEvent::query()->create([
            'account_id' => $world['account']->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->id,
            'action' => 'ticket.escalated',
            'metadata' => ['reason' => 'Datenpunkt reason'],
            'occurred_at' => now(),
        ]);

        $note = Ticket::query()->find($ticket->id)->latestLifecycleNote();

        // The English answer survives for unextracted consumers...
        expect($note['actor'])->toBe($expected)
            // ...and the key is there for the ones that translate.
            ->and($note['actor_key'])->toBe($expected === 'Visitor' ? 'actor_visitor' : 'actor_system');
    }

    // A real actor name is DATA: it is returned as itself and has no key.
    $named = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt named', 'description' => 'Datenpunkt body']);

    AuditEvent::query()->create([
        'account_id' => $world['account']->id,
        'actor_type' => $agent->getMorphClass(),
        'actor_id' => $agent->id,
        'subject_type' => $named->getMorphClass(),
        'subject_id' => $named->id,
        'action' => 'ticket.escalated',
        'metadata' => ['reason' => 'Datenpunkt reason'],
        'occurred_at' => now(),
    ]);

    $note = Ticket::query()->find($named->id)->latestLifecycleNote();

    expect($note['actor'])->toBe($agent->name)
        ->and($note['actor_key'])->toBeNull();
});

test('the narrowed ticket heading takes the dative after von', function (): void {
    // German inflects the adjective for CASE as well as number, and after a
    // bare numeral it takes strong endings. Ticket is neuter, so the dative
    // singular is -em; Unterhaltung is feminine and takes -er, which is why the
    // two queues read differently for what looks like the same sentence.
    App::setLocale('de');

    expect(trans_choice('tickets.counts.matching_tickets', 1, ['count' => 1]))->toBe('1 passendem Ticket')
        ->and(trans_choice('tickets.counts.matching_tickets', 3, ['count' => 3]))->toBe('3 passenden Tickets')
        // Neither is the nominative a word-for-word translation produces.
        ->and(trans_choice('tickets.counts.matching_tickets', 1, ['count' => 1]))->not->toContain('passendes');
});

test('every external attempt branch is translated, including the fall-through', function (): void {
    // Three branches produce an attempt cue and I extracted two of them. The
    // third is the FALL-THROUGH -- every audit action that is not a create or a
    // remove, `ticket.external_sync_failed` among them -- and it is the most
    // common of the three. The link-based fixture never reaches it, so it read
    // English on a German page while the guard stayed green.
    $world = conversationQueueLanguageWorld(conversations: 1);
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['de'];

    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt sync', 'description' => 'Datenpunkt body']);

    foreach ([
        // action, whether metadata carries a project key
        ['ticket.external_sync_failed', true],
        ['ticket.external_issue_created', false],
        ['ticket.external_link_removed', false],
    ] as [$action, $withProject]) {
        AuditEvent::query()->create([
            'account_id' => $world['account']->id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->id,
            'action' => $action,
            'metadata' => $withProject ? ['provider' => 'github', 'project_key' => 'Datenpunkt/repo'] : ['provider' => 'github'],
            'occurred_at' => now()->addSecond(),
        ]);

        $cue = TicketExternalIssueAttempt::latestCueForTicket(Ticket::query()->find($ticket->id));

        App::setLocale('de');
        $german = TicketExternalIssueAttempt::latestCueForTicket(Ticket::query()->find($ticket->id));

        App::setLocale('en');
        $english = TicketExternalIssueAttempt::latestCueForTicket(Ticket::query()->find($ticket->id));

        expect($cue)->not->toBeNull()
            // Not `toBe($x, $message)` -- Pest's matchers take the message
            // differently and a stray second argument changes the assertion.
            ->and($german['label'])->not->toBe($english['label'])
            ->and($german['body'])->not->toBe($english['body']);
    }

    // And the project fallback, which is COPY rather than data -- asserted on
    // the rendered cue, not on the catalogue. Asserting `trans()` only proves
    // the key exists; it says nothing about whether the code path uses it, and
    // a mutation of that path survived exactly that.
    AuditEvent::query()->create([
        'account_id' => $world['account']->id,
        'actor_type' => $agent->getMorphClass(),
        'actor_id' => $agent->id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'action' => 'ticket.external_link_removed',
        // No project key, which is what makes the fallback render.
        'metadata' => ['provider' => 'github'],
        'occurred_at' => now()->addMinute(),
    ]);

    App::setLocale('de');
    $german = TicketExternalIssueAttempt::latestCueForTicket(Ticket::query()->find($ticket->id));

    expect($german['body'])->toContain('Projekt nicht erfasst')
        ->and($german['body'])->not->toContain('Project not recorded');
});
