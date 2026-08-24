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
use App\Support\CobrowseConsentState;
use App\Support\CobrowseReplayPreview;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\CobrowseSnapshotFreshness;
use App\Support\CobrowseTransportPressure;
use App\Support\DashboardLanguage;
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

    // The ROOT is the shell's language, which is still English. The page
    // region carries the agent's.
    $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('<main class="page" lang="de"', false)
        // And the crumb, which on THIS surface takes the rail label -- shell
        // copy, still English -- inside a page that is German. The queue is the
        // first surface where those two differ, and the layout's branch for it
        // was untestable until this PR.
        ->assertSee('<span class="wf-crumb-current" lang="en">Conversations', false);

    $this->actingAs($world['agents']['en'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('<main class="page" lang="en"', false);
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
    if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $main) === 1) {
        $html = $main[1];
    }

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

    $states = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.conversations.index', ['conversation_filter' => 'closed']),
        route('dashboard.conversations.index', ['conversation_filter' => 'assigned_to_me']),
        route('dashboard.conversations.index', ['conversation_search' => 'zzzz']),
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
});

test('every cognate on the list still appears, so the list cannot rot', function (): void {
    // An allowlist nobody rechecks becomes a place real misses hide. If one of
    // these stops rendering, or gets translated after all, this fails and the
    // entry has to go rather than quietly covering something else.
    $world = conversationQueueLanguageWorld();

    $announced = array_column(array_merge(
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['agents']['de'])->get(route('dashboard.profile.show'))->getContent()
        ),
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
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
