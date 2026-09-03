<?php

// The conversation queue in two languages (#749). This is the surface an agent
// looks at most, and the one where the copy is least visible in the view: the
// Blade file holds about seven sentences and the controller builds sixty.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Article;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ExternalIssueProviderConnection;
use App\Models\ReplyTemplate;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Support\AgentReplyTemplate;
use App\Support\CobrowseConsentState;
use App\Support\CobrowseReplayPreview;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\CobrowseSnapshotFreshness;
use App\Support\CobrowseTransportPressure;
use App\Support\DashboardLanguage;
use App\Support\ExternalIssueSyncStatus;
use App\Support\ReplyTemplateOptions;
use App\Support\TicketExternalIssueAttempt;
use App\Support\VisitorSessionToken;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

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
function conversationQueueLanguageTicketStates(array $world, Conversation $conversation): Ticket
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

    return $escalated;
}

/**
 * Provider rows for every conditional section on the integrations page.
 *
 * @param  array{account: Account, site: Site}  $world
 */
function conversationQueueLanguageIntegrationStates(array $world): void
{
    if ($world['account']->externalIssueProviderConnections()->exists()) {
        return;
    }

    $github = ExternalIssueProviderConnection::factory()->for($world['account'])->create([
        'name' => 'Datenpunkt GitHub connection',
        'provider' => 'github',
        'base_url' => 'https://datenpunkt.example/github',
        'credentials' => ['token' => 'datenpunkt-token'],
    ]);

    ExternalIssueProviderConnection::factory()->for($world['account'])->create([
        'name' => 'Datenpunkt GitLab connection',
        'provider' => 'gitlab',
        'credentials' => ['token' => 'datenpunkt-token', 'webhook_secret' => 'datenpunkt-secret'],
    ]);

    ExternalIssueProviderConnection::factory()->for($world['account'])->create([
        'name' => 'Datenpunkt Jira connection',
        'provider' => 'jira',
        'credentials' => ['token' => 'datenpunkt-token', 'webhook_secret' => 'datenpunkt-secret'],
        'settings' => [
            'inbound_webhook' => [
                'verified' => true,
                'event' => 'datenpunkt_event',
                'status_code' => 202,
            ],
        ],
        'last_checked_at' => now()->subMinutes(3),
    ]);

    ExternalIssueProviderConnection::factory()->for($world['account'])->create([
        'name' => 'Datenpunkt disabled connection',
        'provider' => 'other',
        'is_enabled' => false,
    ]);

    $world['site']->externalIssueProjects()->create([
        'account_id' => $world['account']->id,
        'external_issue_provider_connection_id' => $github->id,
        'project_key' => 'datenpunkt/project',
        'project_name' => 'Datenpunkt project',
        'web_url' => 'https://datenpunkt.example/project',
        'settings' => [],
    ]);
}

/**
 * Named for this file rather than for the concept. Pest helpers are global, and
 * a name like `queueWorld()` collides with whatever the next file wants.
 *
 * @return array{account: Account, site: Site, agents: array<string, User>}
 */
/**
 * The conversation the fixture gave a cobrowse session to.
 *
 * NOT `Conversation::query()->firstOrFail()`. Without an ORDER BY, "first" is
 * whatever the planner feels like returning: SQLite hands back the earliest
 * inserted row, PostgreSQL makes no such promise, and the fixture attaches its
 * only cobrowse session to exactly one of three conversations. Five tests in
 * this file were one planner decision away from ModelNotFoundException, and on
 * PostgreSQL one of them took it.
 *
 * Reading the session FIRST removes the coin flip: there is only one, so its
 * conversation is the one that can answer questions about cobrowse.
 */
function conversationQueueLanguageCobrowseSession(): CobrowseSession
{
    return CobrowseSession::query()->firstOrFail();
}

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
    // Presence REPORTING on, or the live board renders its disabled notice and
    // nothing else -- no table, no rows, no script. The first version of this
    // world left it off and the audit measured one paragraph of a page whose
    // copy is mostly in the other branch.
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Datenpunkt Docs',
        'settings' => ['presence' => ['enabled' => true]],
    ]);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-datenpunkt',
        // A name of its own rather than faker's, because the live board renders
        // it and a guard has to be able to find it.
        'name' => 'Acme Datenpunkt Person',
        // On the site NOW, so the live board has a row rather than its empty
        // state. Named, so the row reaches the branch that links to a profile
        // and counts conversations.
        'last_web_seen_at' => now()->subMinute(),
        // The board reads the page address out of metadata, not a column.
        'metadata' => ['last_page_url' => 'https://acme.example/datenpunkt/preise'],
    ]);

    // And a second, never in touch, which is a different row: no link, no
    // name, and the "not in touch yet" sentence instead of a count.
    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-datenpunkt-stranger',
        'last_web_seen_at' => now()->subMinutes(2),
        'presence_only' => true,
    ]);

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

    // A granted cobrowse session, so the detail page's cobrowse panel actually
    // renders. Without one the panel is nearly all absent, and the marker tests
    // above assert over a handful of elements while believing they cover it --
    // a mutation that mismarked a lifecycle timestamp survived for exactly this
    // reason.
    $first = Conversation::query()->where('support_code', 'WF-LANG'.$run.'1')->first();

    if ($first) {
        CobrowseSession::factory()->for($first)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(2),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(20)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
                // A snapshot, a mutation stream and page state, so the
                // replay preview, the drift line, the snapshot panel and the
                // page-state grid all render. Without these that half of the
                // panel is absent and every assertion over it is vacuous --
                // two mutations survived on exactly that.
                'snapshot' => [
                    'reported_at' => now()->subMinutes(9)->toJSON(),
                    'node_count' => 120,
                    'masked_count' => 4,
                    'html' => '<p>Datenpunkt</p>',
                    'text' => 'Datenpunkt snapshot text',
                    'page_url' => 'https://datenpunkt.test/checkout',
                    'title' => 'Datenpunkt checkout',
                ],
                'mutations' => [
                    'batch_count' => 3,
                    'mutation_count' => 7,
                    'dropped_count' => 2,
                    'skipped_count' => 1,
                    'last_sequence' => 12,
                    'last_page_url' => 'https://datenpunkt.test/checkout',
                ],
                'page_state' => [
                    'viewport_width' => 1440,
                    'viewport_height' => 900,
                    'scroll_x' => 0,
                    'scroll_y' => 240,
                    'focused' => true,
                    'page_url' => 'https://datenpunkt.test/checkout',
                    'title' => 'Datenpunkt checkout',
                    'visibility_state' => 'visible',
                ],
                // A fulfilled resync request, so the recovery timeline renders.
                // Without one the timeline is absent and its timestamps are
                // audited nowhere -- a mutation that dropped their language
                // marker survived every other state in this file.
                'resync_request' => [
                    'id' => 'resync-language-fixture',
                    'requested_by_name' => 'Support',
                    'requested_at' => now()->subMinutes(2)->toJSON(),
                    'fulfilled_at' => now()->subSeconds(30)->toJSON(),
                    'fulfilled_snapshot_reported_at' => now()->subSeconds(25)->toJSON(),
                    // An ignored response, so the timeline's `ignored` branch
                    // renders. Without one that branch built its timestamp the
                    // old way and no test could see it.
                    'ignored_responses' => [
                        ['reason' => 'stale', 'ignored_at' => now()->subSeconds(40)->toJSON()],
                    ],
                ],
            ],
        ]);
    }

    // A published article, so the articles DETAIL page has something to render.
    // Its own title and body are account content and stay identical in both
    // languages -- the token marks them as data, the way the account name does.
    $article = Article::factory()->for($account)->create([
        'title' => 'Acme Datenpunkt Artikel',
        // The slug is DATA and is rendered on the detail page, so it carries
        // the token too. Left to the factory it is faker output, which the
        // guard correctly reports as a string identical in both renders.
        'slug' => 'acme-datenpunkt-artikel',
        'body' => 'Acme Datenpunkt.',
        'published_at' => now(),
    ]);

    // API tokens, one per branch of the tokens table. A single active row
    // leaves the expiry, revocation, purged-site, no-abilities and never-used
    // copy unrendered -- on this page most of the sentences ARE the branches,
    // so a one-row fixture would measure the headings and little else.
    // A name of its own rather than another `Ada Datenpunkt`: every agent in
    // this world is called that, and the topbar renders the viewer's name on
    // every page -- so a guard looking for the ISSUER by name would match the
    // person reading the page instead.
    $issuer = User::factory()->for($account)->create(['name' => 'Ausgeber Datenpunkt']);

    // Active, unrestricted, used, and issued by somebody -- the only row that
    // reaches the `created_by` form of the sentence.
    ApiToken::factory()->for($account)->create([
        'name' => 'Acme Datenpunkt Sync',
        'created_by_id' => $issuer->id,
        'last_four' => 'a1b2',
        'last_used_at' => now()->subHours(3),
    ]);

    // Expiring rather than expired, restricted to a site it can name, and
    // never used.
    $restricted = ApiToken::factory()->for($account)->create([
        'name' => 'Acme Datenpunkt Report',
        'last_four' => 'c3d4',
        'restricts_sites' => true,
        'expires_at' => now()->addDays(30),
    ]);
    $restricted->sites()->attach($site);

    ApiToken::factory()->for($account)->create([
        'name' => 'Acme Datenpunkt Legacy',
        'last_four' => 'e5f6',
        'expires_at' => now()->subDay(),
    ]);

    // Revoked, with no abilities, and restricted to sites that have all since
    // been purged. That last combination reaches NOTHING, which is the
    // opposite of what an empty site list means for an unrestricted token --
    // the two have separate sentences and this is the only row that renders
    // the second one.
    ApiToken::factory()->for($account)->create([
        'name' => 'Acme Datenpunkt Retired',
        'last_four' => 'g7h8',
        'abilities' => [],
        'restricts_sites' => true,
        'revoked_at' => now()->subWeek(),
    ]);

    $agents = [
        'en' => User::factory()->for($account)->create(['locale' => 'en', 'name' => 'Ada Datenpunkt']),
        'de' => User::factory()->for($account)->create(['locale' => 'de', 'name' => 'Ada Datenpunkt']),
    ];
    $admins = [
        'en' => User::factory()->for($account)->create(['locale' => 'en', 'name' => 'Ada Datenpunkt', 'account_role' => AccountRole::Admin]),
        'de' => User::factory()->for($account)->create(['locale' => 'de', 'name' => 'Ada Datenpunkt', 'account_role' => AccountRole::Admin]),
    ];

    // Both rows the account-audit screen needs: a populated reference row and
    // the otherwise easy-to-miss system/account fallbacks. The unknown action
    // also proves the localized fallback rather than a headline-cased English
    // identifier reaches the page.
    AuditEvent::factory()->for($account)->for($site)->create([
        'actor_type' => $issuer->getMorphClass(),
        'actor_id' => $issuer->id,
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'action' => 'site_access.updated',
        'metadata' => [],
        'occurred_at' => now()->subMinute(),
    ]);
    AuditEvent::factory()->for($account)->create([
        'actor_type' => null,
        'actor_id' => null,
        'subject_type' => null,
        'subject_id' => null,
        'action' => 'datenpunkt.future_action',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    // Every section of the account-side operator-access page. These are
    // deliberately different scope and lifecycle branches so the extracted
    // route audit measures the row copy, not just three empty-state messages.
    $operator = User::factory()->for($account)->create([
        'name' => 'Betreiber Datenpunkt',
        'platform_role' => 'operator',
        'locale' => 'de',
    ]);
    $operators = [
        'de' => $operator,
        'en' => User::factory()->for($account)->create([
            'name' => 'Operator Datenpunkt',
            'platform_role' => 'operator',
            'locale' => 'en',
        ]),
    ];

    if ($first !== null) {
        BreakGlassGrant::factory()
            ->scopedToConversation($first)
            ->create([
                'requester_id' => $operator->id,
                'reason' => 'Datenpunkt ausstehender Grund',
            ]);
    }

    $operatorGrant = BreakGlassGrant::factory()
        ->activeFor($account, $operator)
        ->scopedToSite($site)
        ->create([
            'approver_id' => $admins['de']->id,
            'self_approved' => false,
            'reason' => 'Datenpunkt aktiver Grund',
        ]);

    BreakGlassGrant::factory()->create([
        'account_id' => $account->id,
        'requester_id' => $operator->id,
        'status' => BreakGlassGrant::STATUS_DENIED,
        'reason' => 'Datenpunkt abgelehnter Grund',
    ]);

    return [
        'account' => $account,
        'site' => $site,
        'article' => $article,
        'agents' => $agents,

        // A second pair with the admin role, for account-management surfaces
        // that 403 without it -- a guard that cannot load a page cannot check
        // it. Kept SEPARATE rather than promoting the pair above, because the
        // role is rendered: making everyone an admin removes `Agent` from the
        // screen, and the cognate list has a guard that notices when one of its
        // entries stops appearing.
        'admins' => $admins,
        'operators' => $operators,
        'operator_grant' => $operatorGrant,
    ];
}

function conversationQueueLanguageReaderForUrl(array $world, string $url, string $locale): User
{
    $path = (string) parse_url($url, PHP_URL_PATH);

    // Break-glass viewer routes are requester-only. Use the grant's requester
    // for both renders and move that reader's locale between requests; the
    // German response is already captured before the English render begins.
    if (str_starts_with($path, '/operator/break-glass')) {
        $operator = $world['operators']['de'];
        $operator->forceFill(['locale' => $locale])->save();

        return $operator->fresh();
    }

    if (str_starts_with($path, '/operator')) {
        return $world['operators'][$locale];
    }

    if (str_starts_with($path, '/dashboard/account')) {
        return $world['admins'][$locale];
    }

    return $world['agents'][$locale];
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
    // Empty, and deliberately kept rather than deleted.
    //
    // It held 'Unavailable' while the cobrowse vocabulary was untranslated. The
    // test below asserted every entry still RENDERED, so that extracting
    // cobrowse would fail it and force the entry out rather than let a stale
    // exemption quietly cover a real miss. That is exactly what happened.
    return [];
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
        $this->assertContains($exception, $rendered,
            "the exemption '{$exception}' no longer renders, so it is covering nothing and should be removed");
    }

    // An empty list is the goal, not a hole: when it empties, the exemption
    // machinery must stop granting anything at all.
    expect(conversationQueueLanguageExceptions())->toBe([],
        'an exemption was added -- record why in docs/product/dashboard-language.md');
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

test('the shared support-code control speaks every extracted surface it is rendered on', function (): void {
    // A shared Blade component, unlike a shared model, may use the catalogue
    // directly: a view is only rendered inside a request, and the locale is
    // scoped per request to surfaces that have been extracted. So the same
    // component renders German everywhere it appears now that all four
    // conversation and ticket surfaces carrying it have been extracted.
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

    // The ticket detail page is the other detail surface using the component.
    $conversation = Conversation::query()->orderByDesc('id')->firstOrFail();

    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt contrast', 'description' => 'Datenpunkt body']);

    $ticketPage = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->getContent();

    expect($ticketPage)->toContain('>Kopieren</button>')
        ->and($ticketPage)->not->toContain('>Copy</button>');
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

test('models stay locale neutral while extracted surfaces translate their state', function (): void {
    // The regression this guards is subtle and was real: `attentionLabel()` and
    // `presenceLabel()` live on models, and a model is read by every surface
    // that touches it. A `__()` there put `Antwort nötig` inside the
    // conversation detail page before that surface was extracted. That was
    // exactly the mixed-language problem the per-surface flag exists to
    // prevent, arriving through the model rather than through the layout.
    //
    // So models answer with STATE and extracted surfaces translate at their own
    // call site. The directory used to be the unextracted contrast in this
    // test; now that it has been extracted, the model itself is the durable
    // contrast -- it must stay English in a German process while the page reads
    // the state and presents German.
    $world = conversationQueueLanguageWorld();

    $visitor = Visitor::query()->where('site_id', $world['site']->id)->where('name', 'Acme Datenpunkt Person')->firstOrFail();

    App::setLocale('de');
    expect($visitor->presenceLabel())->toBe('Active recently');

    $directory = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.visitors.index'))
            ->assertOk()
            ->getContent()
    );

    expect($directory)->toContain('Kürzlich aktiv')
        ->and($directory)->not->toContain('Active recently');

    // And the queue, which IS extracted, still says it in German -- so this is
    // measuring where the translation happens rather than that it stopped.
    $queue = conversationQueueLanguageVisibleText(
        $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.index'))->getContent()
    );

    expect($queue)->toContain('Wartet auf Besucher');
});

test('visitor surfaces translate their copy and keep account content language neutral', function (): void {
    $world = conversationQueueLanguageWorld();
    $visitor = Visitor::query()->where('site_id', $world['site']->id)->where('name', 'Acme Datenpunkt Person')->firstOrFail();
    $visitor->forceFill([
        'external_id' => 'datenpunkt-host-id',
        'metadata' => [
            'last_page_url' => 'https://acme.example/datenpunkt/preise',
            'context' => ['Datenpunkt field' => 'Datenpunkt value'],
        ],
    ])->save();

    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($visitor, 'requester')
        ->create(['category' => null, 'subject' => 'Datenpunkt uncategorized']);

    $xpathFor = function (string $html): DOMXPath {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($document);
    };

    $directoryHtml = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.visitors.index'))->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('Besuchende')
        ->assertDontSee('Search visitors')
        ->getContent();
    $directory = $xpathFor($directoryHtml);

    foreach ([
        'the site filter option' => '//option[normalize-space(text())="Acme Datenpunkt Docs"]',
        'the visitor name' => '//a[normalize-space(text())="Acme Datenpunkt Person"]',
        'the site name in the visitor row' => '//span[normalize-space(text())="Acme Datenpunkt Docs"]',
    ] as $label => $query) {
        $node = $directory->query($query)->item(0);

        expect($node)->not->toBeNull("{$label} did not render; this guard is checking nothing")
            ->and($node)->toBeInstanceOf(DOMElement::class)
            ->and($node->hasAttribute('lang'))->toBeTrue("{$label} carries no language reset")
            ->and($node->getAttribute('lang'))->toBe('');
    }

    $emptySearch = $directory->query('//input[@id="search"]')->item(0);

    expect($emptySearch)->not->toBeNull('the empty search field did not render; this guard is checking nothing')
        ->and($emptySearch)->toBeInstanceOf(DOMElement::class)
        ->and($emptySearch->hasAttribute('lang'))->toBeFalse('the translated placeholder was reset to an unknown language');

    $searchedDirectory = $xpathFor((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.visitors.index', ['search' => 'Datenpunkt Person']))->assertOk()
        ->getContent());
    $filledSearch = $searchedDirectory->query('//input[@id="search"]')->item(0);

    expect($filledSearch)->not->toBeNull('the filled search field did not render; this guard is checking nothing')
        ->and($filledSearch)->toBeInstanceOf(DOMElement::class)
        ->and($filledSearch->hasAttribute('lang'))->toBeTrue('the agent-entered search term carries no language reset')
        ->and($filledSearch->getAttribute('lang'))->toBe('');

    $profileHtml = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.visitors.show', $visitor))->assertOk()
        ->assertSee('Besucherprofil')
        ->assertSee('Ohne Kategorie')
        ->assertDontSee('Visitor profile')
        ->getContent();
    $profile = $xpathFor($profileHtml);

    foreach ([
        'the site name in the profile heading' => '//header//span[normalize-space(text())="Acme Datenpunkt Docs"]',
        'the host visitor id' => '//span[normalize-space(text())="datenpunkt-host-id"]',
        'the host context field' => '//td[normalize-space(text())="Datenpunkt field"]',
        'the host context value' => '//td[normalize-space(text())="Datenpunkt value"]',
        'the ticket subject' => '//a[normalize-space(text())="Datenpunkt uncategorized"]',
    ] as $label => $query) {
        $node = $profile->query($query)->item(0);

        expect($node)->not->toBeNull("{$label} did not render; this guard is checking nothing")
            ->and($node)->toBeInstanceOf(DOMElement::class)
            ->and($node->hasAttribute('lang'))->toBeTrue("{$label} carries no language reset")
            ->and($node->getAttribute('lang'))->toBe('');
    }

    $world['agents']['de']->forceFill(['locale' => 'it'])->save();
    $italianProfile = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.visitors.show', $visitor))->assertOk()
            ->getContent()
    );

    expect($italianProfile)->toContain('3 visualizzate')
        ->toContain('1 visualizzato')
        ->not->toContain('3 visualizzati');

    App::setLocale('it');

    expect(trans_choice('visitors.counts.shown_conversations', 1, ['count' => 1]))->toBe('1 visualizzata')
        ->and(trans_choice('visitors.counts.shown_conversations', 2, ['count' => 2]))->toBe('2 visualizzate')
        ->and(trans_choice('visitors.counts.shown_tickets', 1, ['count' => 1]))->toBe('1 visualizzato')
        ->and(trans_choice('visitors.counts.shown_tickets', 2, ['count' => 2]))->toBe('2 visualizzati');
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
        ->and($germanLabels)->toBe(['Suche', 'Website', 'Präsenzstatus']);

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
    expect($german)->not->toContain('erfordert Aufmerksamkeit von')
        ->and($german)->not->toContain('erfordern Aufmerksamkeit von')
        ->and($english)->not->toContain('needs attention shown of');

    expect($german)->toContain('1 von 3 passenden Unterhaltungen erfordert Aufmerksamkeit')
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

test('the queue cobrowse cell marks only what is still English', function (): void {
    // This test used to assert the whole cell claimed English. The transport
    // vocabulary is extracted now, so the cell inherits the document language
    // and only the values that are STILL English carry a marker.
    //
    // The awkward half is unchanged: a German label wrapping a value that may
    // be English, in one sentence whose word order the catalogue owns.
    // Splitting it to wrap the value would be the fragment concatenation this
    // extraction refuses, so the marked value goes in as the placeholder.
    $world = conversationQueueLanguageWorld();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('class="wf-queue-cobrowse"');

    // The cell no longer claims English, because its copy is translated.
    expect($html)->not->toMatch('/<span\s+class="wf-queue-cobrowse"\s+lang="en"/');

    // And it renders German.
    expect($html)->toContain(__('cobrowse.transport.inactive.label', [], 'de'));

    // `last_report` with no report yet is translated, where it used to be the
    // English literal marked English. This is the queue's most common row --
    // a conversation with no cobrowse session at all takes the default payload
    // -- so it was the most repeated untranslated string on the page, once per
    // row, and the marker made it look deliberate.
    expect($html)->toContain('Letzte Meldung <span lang="de">'.__('cobrowse.units.not_reported', [], 'de').'</span>');

    $this->assertStringNotContainsString(__('cobrowse.units.not_reported', [], 'en'), $html,
        'the English not-reported literal is still on the German queue');

    // An English agent gets the same sentence in English. The marker follows
    // the page's language now, because both branches of the value do.
    $inEnglish = (string) $this->actingAs($world['agents']['en'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    expect($inEnglish)->toContain('Last report <span lang="en">'.__('cobrowse.units.not_reported', [], 'en').'</span>');
});

test('a localised cobrowse timestamp is marked German, not English', function (): void {
    // With a report, `last_report` is built by `diffForHumans()`, which follows
    // the page's locale -- so on this route it is already German. Marking that
    // English has a screen reader pronounce German as English, which is the
    // same defect as leaving it unmarked, pointing the other way.
    //
    // Decided from the model's `last_report_reported`, not from the state and
    // not by reading the prose. The state agreed with the discriminator here,
    // which is exactly why deciding from it looked correct for so long.
    $world = conversationQueueLanguageWorld();

    $this->instance(CobrowseConsentState::class, new class(app(CobrowseReplayPreview::class), app(CobrowseResyncRequestPolicy::class), app(CobrowseSnapshotFreshness::class), app(CobrowseTransportPressure::class)) extends CobrowseConsentState
    {
        public function queueTransportForConversation(Conversation $conversation): array
        {
            return [
                'state' => 'live',
                'copy' => 'live',
                'guidance_copy' => 'guidance',
                'has_pressure' => true,
                'label' => 'Live',
                'message' => 'x',
                // What `diffForHumans()` returns once the locale is German.
                'last_report' => 'vor 20 Sekunden',
                'last_report_reported' => true,
                'pressure' => '2 dropped batches',
                // The counts, which is what the row now composes from. The
                // English string above is left in place because other callers
                // still read it; the queue no longer does.
                'pressure_counts' => ['dropped_batches' => 2, 'skipped_mutations' => 0],
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
        // The pressure value used to be static English -- English words and an
        // English pluraliser -- and this asserted that it was at least MARKED
        // English. It is now composed from the counts in the reader's language,
        // so there is nothing left to mark and nothing left in English.
        ->and($html)->toContain(trans_choice('cobrowse.pressure.dropped', 2, ['count' => 2], 'de'))
        ->and($html)->not->toContain('2 dropped batches');
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
                'copy' => 'inactive',
                'guidance_copy' => 'guidance',
                'has_pressure' => true,
                'label' => 'Unavailable',
                'message' => 'x',
                // Reported, so the value below is what renders. Left unreported
                // this test would assert escaping of a string the view never
                // takes from the model.
                'last_report_reported' => true,
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
/**
 * Prose an agent reads, as opposed to identifiers that merely contain spaces.
 *
 * A class list -- `reply-attach-chip reply-attach-chip--error` -- has spaces
 * and letters and is not copy. Every token in one is lowercase kebab-case,
 * which no English sentence is.
 */
function conversationQueueLanguageIsProse(string $literal): bool
{
    if (preg_match('/[A-Za-z]{2,}\s+[A-Za-z]{2,}/', $literal) !== 1) {
        return false;
    }

    foreach (preg_split('/\s+/', trim($literal)) ?: [] as $token) {
        if (preg_match('/^[a-z0-9-]+$/', $token) !== 1) {
            return true;
        }
    }

    return false;
}

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
        'System' => 'the same word in both languages',
        'Cobrowse' => "the product's own name for the feature, not translated",
        'Wayfindr' => 'the product name, which is not copy',
        'Tickets' => 'the same word in both languages',
        'Ticket' => 'the same word in both languages, singular',
        'Status' => 'the same word in both languages',
        'Normal' => 'the same word in both languages, as a priority',
        'Live' => 'the same word in both languages, as a transport state',
        'URL' => 'the same word in both languages',
        'Token' => 'the same word in both languages -- German writes DAS Token and hyphenates the compound, so the bare column header is identical while the page title is not',
        'Label' => 'a loanword German uses as-is',
        'Labels' => 'a loanword German uses as-is',
        'Scanner' => 'a loanword German uses as-is',
        'Transport' => 'the same technical term in German and English',
        'English' => 'an autonym -- the language selector names each language in its own language',
        'Deutsch' => 'an autonym -- see above',
        'Italiano' => 'an autonym -- see above',
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

    // The token match is deliberately case-INSENSITIVE. Fixture data does not
    // always reach the page in the casing it was written in: an article's slug
    // is `acme-datenpunkt-artikel`, lower-cased by the same slug rule the
    // product uses, and a marker that only survives in its original casing
    // stops marking things the moment the product touches them. Nothing this
    // platform says in English contains `datenpunkt` in any casing.
    $isData = fn (string $text): bool => stripos($text, 'Datenpunkt') !== false
        || str_contains($text, 'WF-LANG')
        || str_contains($text, 'anon-')
        // A token hint (`wfk_…a1b2`) is the credential's own identifier, and
        // the prefix alone reads as a three-letter word to the check below.
        || str_starts_with($text, ApiToken::PREFIX)
        || str_contains($text, '@')
        // IANA time zone identifiers. `Europe/Berlin` is a NAME, not copy:
        // the same string in every language by design, and a translated one is
        // a value the platform's own zone database rejects.
        //
        // Asked of the zone database rather than matched on shape. A shape
        // rule looked right and quietly missed the abbreviation forms --
        // `UTC`, `GMT`, `CET` -- which are identifiers too and read as
        // three-letter words to every other check here.
        || in_array($text, DateTimeZone::listIdentifiers(), true)
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
    $profileVisitor = $conversation->visitor()->firstOrFail();
    $profileVisitor->forceFill([
        'external_id' => 'datenpunkt-host-id',
        'metadata' => [
            'last_page_url' => 'https://acme.example/datenpunkt/preise',
            'context' => ['Datenpunkt field' => 'Datenpunkt value'],
        ],
    ])->save();
    $sparseVisitor = Visitor::factory()->for($world['site'])->create([
        'anonymous_id' => 'anon-datenpunkt-sparse',
        'external_id' => null,
        'metadata' => [],
    ]);

    // Enough rows for the framework paginator to render. Its copy lives in a
    // shared vendor view rather than this surface, so a populated first page is
    // the only way the extracted-route audit can catch it falling back to
    // English.
    for ($index = 1; $index <= 26; $index++) {
        Visitor::factory()->for($world['site'])->create([
            'anonymous_id' => 'anon-datenpunkt-page-'.$index,
            'name' => 'Datenpunkt page visitor '.$index,
            'last_seen_at' => now()->subMinutes($index),
        ]);
    }

    $breakGlassTicket = Ticket::factory()
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

    $ticketWorkspace = conversationQueueLanguageTicketStates($world, $conversation);
    conversationQueueLanguageIntegrationStates($world);

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
        route('dashboard.tickets.show', $ticketWorkspace),
        route('dashboard.tickets.show', ['ticket' => $ticketWorkspace, 'timeline_filter' => 'conversation']),
        route('dashboard.tickets.show', ['ticket' => $ticketWorkspace, 'timeline_filter' => 'internal_notes']),
        route('dashboard.tickets.show', ['ticket' => $ticketWorkspace, 'timeline_filter' => 'ticket_activity']),
        route('dashboard.conversations.show', $conversation->support_code),
        route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'tab' => 'context']),
        route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'tab' => 'ticket']),
        route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'tab' => 'cobrowse']),
        route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'tab' => 'references']),
        route('dashboard.account.reply-templates.index'),
        route('dashboard.account.labels.index'),
        route('dashboard.account.api-tokens.index'),
        route('dashboard.account.audit.index'),
        route('dashboard.account.break-glass.index'),
        route('dashboard.account.integrations'),
        route('dashboard.account.show'),
        route('operator.settings.localization.edit'),
        route('operator.settings.scanning.edit'),
        route('operator.settings.mail.edit'),
        route('operator.settings.storage.edit'),
        route('operator.settings.backups.edit'),
        route('operator.settings.backups.history'),
        route('operator.settings.backups.restore'),
        route('operator.dashboard'),
        route('operator.onboarding'),
        route('operator.break-glass.index'),
        route('operator.break-glass.show', $world['operator_grant']),
        route('operator.break-glass.conversations.show', [$world['operator_grant'], $conversation]),
        route('operator.break-glass.tickets.show', [$world['operator_grant'], $breakGlassTicket]),
        route('dashboard.account.audit.index', [
            'audit_action' => 'site_access.updated',
            'audit_search' => 'Datenpunkt',
            'audit_site' => $world['site']->id,
        ]),
        route('dashboard.account.audit.index', ['audit_search' => 'zzzz']),
        route('dashboard.sites.live', $world['site']),
        route('dashboard.visitors.index'),
        route('dashboard.visitors.index', ['page' => 2]),
        route('dashboard.visitors.index', ['search' => 'zzzz']),
        route('dashboard.visitors.show', $profileVisitor),
        route('dashboard.visitors.show', $sparseVisitor),
        route('dashboard.account.articles.index'),
        // The DETAIL page explicitly. The prefix match above would let it pass
        // on the index's coverage without ever rendering it -- which the loop's
        // own comment names as how `conversations.show` went unaudited.
        route('dashboard.account.articles.show', $world['article']),
    ];

    // Every GET-able extracted route is covered, whether or not it is listed
    // above -- so this fails loudly when a surface is extracted without being
    // added here, rather than silently skipping it.
    $covered = collect($states)->map(fn (string $url): string => parse_url($url, PHP_URL_PATH))->all();

    foreach (DashboardLanguage::EXTRACTED_ROUTES as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        // A route WITH parameters is matched by its static prefix rather than
        // skipped. Skipping them silently is how `conversations.show` joined
        // the extracted list and was never audited -- the guard passed because
        // it had quietly decided not to look.
        $prefix = rtrim('/'.ltrim(explode('{', $route->uri())[0], '/'), '/');
        $matched = collect($covered)->contains(fn (string $path): bool => str_starts_with($path, $prefix));

        // Not `expect()->toContain()`: that is variadic, so a message passed
        // as a second argument becomes a second required value and the failure
        // reports the message itself as missing.
        $this->assertTrue($matched, "extracted route not audited: {$name} ({$route->uri()})");
    }

    // And the same question asked from the other end, which is the direction
    // that was missing. The loop above proves every extracted route is
    // rendered here; it cannot notice a route that is rendered here and is no
    // longer extracted.
    //
    // That gap is not theoretical -- deleting the articles routes from
    // EXTRACTED_ROUTES left this whole file green. The page drops back to
    // `lang="en"`, the audit only inspects surfaces that ANNOUNCE German, and a
    // page that stops claiming German stops being looked at. The check and the
    // behaviour were both defined by the same list, so removing an entry
    // removed the thing that would have complained.
    //
    // `$states` is the deliberate statement of which pages are meant to speak
    // the agent's language, so it is the honest place to hold that list to.
    foreach ($states as $url) {
        $name = app('router')->getRoutes()
            ->match(Request::create(parse_url($url, PHP_URL_PATH), 'GET'))
            ->getName();

        $this->assertContains($name, DashboardLanguage::EXTRACTED_ROUTES, implode(' ', [
            "audited page is not an extracted route: {$name}.",
            'It renders in English for every agent, and this audit skips it in',
            'silence because it never announces German.',
        ]));
    }

    foreach ($states as $url) {
        $leaks = conversationQueueLanguageEnglishLeaks(
            (string) $this->actingAs(conversationQueueLanguageReaderForUrl($world, $url, 'de'))->get($url)->assertOk()->getContent(),
            (string) $this->actingAs(conversationQueueLanguageReaderForUrl($world, $url, 'en'))->get($url)->assertOk()->getContent(),
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

    // Same lesson again, from the other direction. The ticket-creation form and
    // the empty transcript exist only BEFORE a conversation has tickets or
    // messages, and the world above has both -- so mutations of the category
    // options, the priority guide and the empty transcript survived every state
    // visited above. A world with neither reaches all three.
    $bare = conversationQueueLanguageWorld();
    $bareConversation = Conversation::query()
        ->where('site_id', $bare['site']->id)
        ->orderBy('id')
        ->firstOrFail();

    expect($bareConversation->messages()->count())->toBe(0, 'the bare conversation has messages, so it renders no empty transcript')
        ->and($bareConversation->tickets()->count())->toBe(0, 'the bare conversation has tickets, so it renders no creation form');

    foreach (['ticket', 'transcript'] as $tab) {
        $url = route('dashboard.conversations.show', [
            'supportCode' => $bareConversation->support_code,
            'tab' => $tab,
        ]);

        $leaks = conversationQueueLanguageEnglishLeaks(
            (string) $this->actingAs($bare['agents']['de'])->get($url)->assertOk()->getContent(),
            (string) $this->actingAs($bare['agents']['en'])->get($url)->assertOk()->getContent(),
        );

        expect($leaks)->toBe([], "announced as German but never translated, at {$url}");
    }

    // And a transcript that HAS messages. The conversation above has none, so
    // every message-level label -- the sender roles among them -- went
    // unrendered and unaudited on this page.
    $spoken = Conversation::query()
        ->where('site_id', $bare['site']->id)
        ->whereHas('messages')
        ->orderBy('id')
        ->firstOrFail();

    expect($spoken->messages()->count())->toBeGreaterThan(0, 'the conversation has no messages, so it renders no transcript');

    $url = route('dashboard.conversations.show', $spoken->support_code);

    $leaks = conversationQueueLanguageEnglishLeaks(
        (string) $this->actingAs($bare['agents']['de'])->get($url)->assertOk()->getContent(),
        (string) $this->actingAs($bare['agents']['en'])->get($url)->assertOk()->getContent(),
    );

    expect($leaks)->toBe([], "announced as German but never translated, at {$url}");

    // And a visitor with no anonymous id. The column is nullable, so this is a
    // real production state, and every fixture above gives its visitor one --
    // so the `Unknown visitor` fallback rendered nowhere and was audited
    // nowhere. Fallback branches need a fixture as much as happy paths do;
    // this is the third state gap this test has been taught.
    $namelessVisitor = Visitor::factory()->for($bare['site'])->create(['anonymous_id' => null]);
    $nameless = Conversation::factory()
        ->for($bare['site'])
        ->for($namelessVisitor)
        ->create(['support_code' => 'WF-LANGNONAME', 'subject' => 'Datenpunkt nameless', 'status' => 'open']);

    expect($nameless->visitor->anonymous_id)->toBeNull('the visitor has an id, so the fallback never renders');

    $url = route('dashboard.conversations.show', $nameless->support_code);

    $leaks = conversationQueueLanguageEnglishLeaks(
        (string) $this->actingAs($bare['agents']['de'])->get($url)->assertOk()->getContent(),
        (string) $this->actingAs($bare['agents']['en'])->get($url)->assertOk()->getContent(),
    );

    expect($leaks)->toBe([], "announced as German but never translated, at {$url}");
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
    $conversation = conversationQueueLanguageCobrowseSession()->conversation;

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
        // The detail page too: `Ticket` singular is a tab label and appears
        // nowhere else, so without this the anti-rot check fails for a cognate
        // that is perfectly real.
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['agents']['de'])->get(route('dashboard.conversations.show', $conversation->support_code))->getContent()
        ),
        // And the API-tokens page, where `Token` is a column header and
        // appears nowhere else. ADMIN rather than agent: the page 403s
        // otherwise, and a page that does not load announces nothing -- which
        // this guard would read as a cognate that has stopped appearing.
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['admins']['de'])->get(route('dashboard.account.api-tokens.index'))->getContent()
        ),
        // The audit page's system actor is another genuine German/English
        // cognate, and its fixture deliberately renders that fallback row.
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['admins']['de'])->get(route('dashboard.account.audit.index'))->getContent()
        ),
        // The scanner label is a German/English loanword, and this is the
        // first extracted surface that renders it as Wayfindr copy.
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['operators']['de'])->get(route('operator.settings.scanning.edit'))->getContent()
        ),
        // The mail transport label is also the same technical term in German.
        conversationQueueLanguageAnnouncements(
            (string) $this->actingAs($world['operators']['de'])->get(route('operator.settings.mail.edit'))->getContent()
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

test('live updates render every field in the reading agent language', function (): void {
    // The realtime handlers do not re-render server-side, so a translated page
    // can revert to English the moment an event arrives. These tables are what
    // the page substitutes in, so they must be complete: a missing entry falls
    // through to a fallback and quietly replaces correct copy with wrong copy.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    // The realtime block is gated on a configured broadcaster, so without this
    // the page renders no script at all and the assertions below would pass
    // over an empty page rather than a translated one.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'language-test-key',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    foreach (['de', 'en'] as $locale) {
        $page = (string) $this->actingAs($world['agents'][$locale])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent();

        preg_match('/var realtimeLabels = (\{.*?\});/s', $page, $found);

        expect($found)->not->toBe([], "no realtime label table rendered in {$locale}");

        $labels = json_decode(html_entity_decode($found[1], ENT_QUOTES), true);

        expect($labels)->toBeArray();

        // Every key the payloads can emit needs an entry. `seen_at` is the one
        // that was missing: a visitor with a heartbeat who has gone quiet fell
        // through to "no heartbeat yet", which is a different fact.
        foreach (['seen_recently', 'seen_at', 'no_heartbeat'] as $key) {
            expect($labels['presenceDetail'][$key] ?? null)->toBeString()
                ->and($labels['presenceDetail'][$key])->not->toBe('');
        }

        foreach (['seen', 'unseen', 'none'] as $key) {
            expect($labels['readDetail'][$key] ?? null)->toBeString();
        }

        foreach (['seen', 'seen_unknown', 'unseen'] as $key) {
            expect($labels['transcript'][$key] ?? null)->toBeString();
        }

        expect($labels['locale'] ?? null)->toBe($locale);

        if ($locale === 'de') {
            // Not merely present -- actually translated. An entry that resolves
            // to the English string is the bug this test exists for.
            expect($labels['presenceDetail']['seen_at'])->toBe(__('conversations.detail.context.seen_at', [], 'de'))
                ->and($labels['presenceDetail']['seen_at'])->not->toBe(__('conversations.detail.context.seen_at', [], 'en'))
                ->and($labels['transcript']['seen'])->not->toBe(__('conversations.detail.reply.seen_by_visitor', [], 'en'))
                ->and($labels['readDetail']['seen'])->not->toBe(__('tickets.read_state.detail_seen', [], 'en'));
        }

        // Templates that take a duration must still carry their placeholder --
        // the page fills it from a timestamp, so a table that shipped a
        // pre-formatted duration would be frozen in one agent's language.
        expect($labels['presenceDetail']['seen_at'])->toContain(':elapsed')
            ->and($labels['transcript']['seen'])->toContain(':elapsed');
    }
});

test('the cobrowse panel is an ordinary part of the page now', function (): void {
    // This test used to assert the panel declared itself English and reset each
    // translated fragment inside it. The vocabulary is extracted, so the
    // declaration is gone and so are the hundred resets that existed only to
    // undo it. The assertion inverted rather than being deleted, which is the
    // whole point of having written it as a condition to be met.
    //
    // What is still marked is marked for what it IS: the visitor's own page
    // title and URLs are neither our English nor the agent's German.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $page = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$page);
    $xpath = new DOMXPath($document);

    $panel = $xpath->query('//*[@data-tab-panel="cobrowse"]')->item(0);

    expect($panel)->not->toBeNull()
        ->and($panel->hasAttribute('lang'))->toBeFalse('the cobrowse panel still declares a language of its own');

    // Nothing inside it claims English any more.
    foreach ($xpath->query('.//*[@lang="en"]', $panel) as $marked) {
        $this->fail('the cobrowse panel still marks copy as English: "'.trim($marked->textContent).'"');
    }

    // And the heading is announced in the agent's language, by inheritance
    // rather than by a reset.
    $heading = $xpath->query('.//*[@id="cobrowse-heading"]', $panel)->item(0);

    expect($heading)->not->toBeNull()
        ->and(trim($heading->textContent))->toBe(__('conversations.detail.tabs.cobrowse', [], 'de'));

    for ($node = $heading; $node instanceof DOMElement; $node = $node->parentNode) {
        if ($node->hasAttribute('lang')) {
            expect($node->getAttribute('lang'))->toBe('de',
                'the cobrowse heading inherits a language that is not the document\'s');

            break;
        }
    }
});

test('every extracted page translates its document title', function (): void {
    // The `<title>` is the tab and the first thing a screen reader announces
    // for the page, and the leak guard could not see this one: the detail
    // page's title carries the support code, and any sentence containing a
    // data token is excluded wholesale as data. Copy wrapped around data is
    // invisible to that heuristic -- the same blind spot the row-copy test
    // exists for, in the one place a page names itself.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $breakGlassTicket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['subject' => 'Datenpunkt title ticket']);

    $titleOf = function (string $url, string $locale) use ($world): string {
        $reader = conversationQueueLanguageReaderForUrl($world, $url, $locale);
        $html = (string) $this->actingAs($reader)->get($url)->assertOk()->getContent();

        preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $found);

        return trim(html_entity_decode($found[1] ?? '', ENT_QUOTES));
    };

    $urls = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.tickets.index'),
        route('dashboard.tickets.show', $breakGlassTicket),
        route('dashboard.conversations.show', $conversation->support_code),
        route('dashboard.account.break-glass.index'),
        route('dashboard.account.integrations'),
        route('dashboard.account.show'),
        route('operator.settings.localization.edit'),
        route('operator.settings.scanning.edit'),
        route('operator.settings.mail.edit'),
        route('operator.settings.storage.edit'),
        route('operator.settings.backups.edit'),
        route('operator.settings.backups.history'),
        route('operator.settings.backups.restore'),
        route('operator.dashboard'),
        route('operator.onboarding'),
        route('operator.break-glass.index'),
        route('operator.break-glass.show', $world['operator_grant']),
        route('operator.break-glass.conversations.show', [$world['operator_grant'], $conversation]),
        route('operator.break-glass.tickets.show', [$world['operator_grant'], $breakGlassTicket]),
    ];

    foreach ($urls as $url) {
        $german = $titleOf($url, 'de');
        $english = $titleOf($url, 'en');

        expect($german)->not->toBe('', "no document title rendered at {$url}");

        // A title that is the same word in both languages is fine, but it has
        // to be a word already declared as one -- not a new coincidence.
        if (array_key_exists($german, conversationQueueLanguageCognates())) {
            continue;
        }

        expect($german)->not->toBe($english, "the document title at {$url} is identical in both languages");
    }
});

test('a ticket is stored in the install language, not the creating agent\'s', function (): void {
    // A ticket's subject and description are written once and read by everyone:
    // other agents on other language settings, notification emails, the API,
    // and whatever external issue tracker the account has linked. Generating
    // them in the creating agent's language puts one person's dashboard
    // preference into shared data permanently, where nothing translates it back.
    $world = conversationQueueLanguageWorld();

    // A conversation with no subject and no messages, so BOTH fallbacks fire.
    $bare = Conversation::factory()
        ->for($world['site'])
        ->for(Visitor::factory()->for($world['site'])->create(['anonymous_id' => 'anon-stored']))
        ->create(['support_code' => 'WF-LANGSTORED', 'subject' => '', 'status' => 'open']);

    $this->actingAs($world['agents']['de'])
        ->from(route('dashboard.conversations.show', $bare->support_code))
        ->post(route('dashboard.conversations.tickets.store', $bare->support_code), [
            'priority' => 'normal',
        ])
        ->assertRedirect();

    $ticket = $bare->tickets()->firstOrFail();

    $installLanguage = DashboardLanguage::forStoredContent();

    expect($ticket->subject)->toBe(__('conversations.detail.ticket_subject_fallback',
        ['code' => $bare->support_code], $installLanguage))
        ->and($ticket->description)->toBe(__('conversations.detail.ticket_from_conversation',
            ['code' => $bare->support_code], $installLanguage));

    // And specifically NOT the German the creating agent was reading.
    expect($ticket->subject)->not->toBe(__('conversations.detail.ticket_subject_fallback',
        ['code' => $bare->support_code], 'de'))
        ->and($ticket->description)->not->toBe(__('conversations.detail.ticket_from_conversation',
            ['code' => $bare->support_code], 'de'));
});

test('nothing on an extracted path throws a literal validation message', function (): void {
    // Scoping a route's locale does nothing for a message built as a PHP
    // string: `ValidationException::withMessages(['file' => 'This file type is
    // not allowed.'])` is English whatever locale is active. The upload path
    // reaches the German composer, and the linked-ticket assignment reaches the
    // German panel, so every message they can throw has to come from the
    // catalogue.
    $files = [
        'app/Support/Attachments/AttachmentUploadService.php',
        'app/Support/Attachments/AttachmentBinder.php',
        'app/Http/Controllers/AgentTicketController.php',
    ];

    $literals = [];

    foreach ($files as $file) {
        $source = file_get_contents(base_path($file));

        expect($source)->not->toBeFalse("{$file} is gone; this guard no longer reads it");

        // Each `withMessages([...])` block, and the strings inside it.
        foreach (preg_split('/withMessages\(\[/', $source) as $index => $chunk) {
            if ($index === 0) {
                continue;
            }

            $block = substr($chunk, 0, strpos($chunk, '])') ?: strlen($chunk));

            // BOTH quote forms. The single-quote-only version of this regex
            // shipped, and missed `"A message can include at most {$max}
            // attachment(s)."` -- which is the one message here that had to be
            // interpolated, and so the one most likely to be written with
            // double quotes. A guard that only recognises the easy spelling is
            // worse than none, because it reports clean.
            preg_match_all('/=> *"([^"\\\\\n]*)"|=> *\'([^\'\\\\\n]*)\'/', $block, $found);

            foreach (array_filter(array_merge($found[1], $found[2])) as $literal) {
                if (conversationQueueLanguageIsProse($literal)) {
                    $literals[] = basename($file).': '.$literal;
                }
            }
        }
    }

    expect($literals)->toBe([], 'a validation message on an extracted path is a PHP string rather than a catalogue key');
});

test('the transcript declares its own language, not the dashboard\'s', function (): void {
    // A conversation's content has nothing to do with the language the agent
    // reads the dashboard in: the visitor wrote in whatever they came in with.
    // Inheriting `lang="de"` from the document has a screen reader pronounce an
    // English conversation with German rules.
    $world = conversationQueueLanguageWorld();
    $spoken = Conversation::query()
        ->where('site_id', $world['site']->id)
        ->whereHas('messages')
        ->orderBy('id')
        ->firstOrFail();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $spoken->support_code))
        ->assertOk()
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $bodies = $xpath->query('//*[contains(@class, "message-body")]');

    expect($bodies->length)->toBeGreaterThan(0, 'no message bodies rendered, so this proves nothing');

    foreach ($bodies as $body) {
        expect($body->hasAttribute('lang'))->toBeTrue('a message body inherits the document language');
        expect($body->getAttribute('lang'))->toBe('', 'a message body claims a language it cannot know');
    }

    // A neighbour with no subject. The switcher's fallback is OUR copy and has
    // to stay in the document language rather than being marked unknown along
    // with the visitor-authored ones -- which means the controller must not
    // normalise null away before the view can tell them apart.
    //
    // Asserted on the view data rather than the rendered switcher: that menu
    // only renders for a conversation with siblings in its window, and the
    // contract being protected here is the shape, not the markup.
    Conversation::factory()
        ->for($world['site'])
        ->for($spoken->visitor)
        ->create(['support_code' => 'WF-LANGNOSUBJ', 'subject' => null, 'status' => 'open']);

    // `from_queue=1` or the switcher is deliberately empty: a conversation
    // opened from a notification or a ticket has no queue to have neighbours in.
    $siblings = $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', [
            'supportCode' => $spoken->support_code,
            'from_queue' => '1',
        ]))
        ->assertOk()
        ->viewData('conversationSiblings');

    $untitledSibling = collect($siblings['items'])->firstWhere('support_code', 'WF-LANGNOSUBJ');

    expect($untitledSibling)->not->toBeNull('the untitled neighbour is not in the switcher, so this proves nothing')
        ->and($untitledSibling['subject'])->toBeNull('the controller normalised the subject away, so the view cannot tell copy from content')
        ->and($untitledSibling['subject_fallback'])->toBeTrue();

    $titledSibling = collect($siblings['items'])->firstWhere('support_code', $spoken->support_code);

    expect($titledSibling['subject'])->toBe($spoken->subject)
        ->and($titledSibling['subject_fallback'])->toBeFalse();

    // Two attachments on the transcript -- an image and a file -- so both the
    // alt text and the visible name render. A filename is whatever the person
    // called it, and the image variant carries it in an ATTRIBUTE.
    $attachedTo = $spoken->messages()->orderBy('id')->firstOrFail();

    ConversationMessageAttachment::factory()->for($attachedTo, 'message')->create([
        'original_filename' => 'Datenpunkt receipt.png',
        'mime_type' => 'image/png',
    ]);
    ConversationMessageAttachment::factory()->for($attachedTo, 'message')->create([
        'original_filename' => 'Datenpunkt invoice.pdf',
        'mime_type' => 'application/pdf',
    ]);

    // A linked ticket, so its work panel renders -- its heading is the stored
    // subject, which is the visitor's words copied across or an agent's own,
    // and either way not the dashboard's language.
    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($spoken)
        ->for($spoken->visitor, 'requester')
        ->create(['status' => 'open', 'priority' => 'normal', 'subject' => 'Datenpunkt linked subject']);

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $spoken->support_code))
        ->assertOk()
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//h3[contains(@id, "-work-heading")]')->length)
        ->toBeGreaterThan(0, 'no linked ticket panel rendered, so this proves nothing');

    // Every user-authored value on this page, found by walking rather than by
    // naming them: the conversation subject, the linked ticket's subject, the
    // ticket activity body, the site name. Codex found these one file at a
    // time across three rounds; this is the sweep that should have come first.
    $authored = [
        '//*[contains(@class, "message-body")]',
        '//h3[contains(@id, "-work-heading")]',
        // A filename is the visitor's or the agent's own words too, and the
        // image variant carries it in an ATTRIBUTE, so the marker is on the
        // element rather than around it.
        '//*[contains(@class, "message-attachment-name")]',
        '//img[contains(@class, "message-attachment-image")]',
    ];

    // Each selector must MATCH something. A foreach over an empty NodeList
    // passes silently, which is how ten previous assertions in this file
    // managed to prove nothing.
    $matched = [];

    foreach ($authored as $selector) {
        $matched[$selector] = $xpath->query($selector)->length;

        foreach ($xpath->query($selector) as $node) {
            expect($node->hasAttribute('lang'))->toBeTrue(
                "a user-authored value at {$selector} inherits the dashboard language");
            expect($node->getAttribute('lang'))->toBe('',
                "a user-authored value at {$selector} claims a language it cannot know");
        }
    }

    foreach ($matched as $selector => $count) {
        expect($count)->toBeGreaterThan(0, "nothing matched {$selector}, so that assertion proved nothing");
    }

    // The subject is the same thing wearing a heading. It is the page's primary
    // heading, and it also appears in the queue switcher and in prior
    // conversations -- all of it the visitor's own words.
    $heading = $xpath->query('//h1')->item(0);

    expect($heading)->not->toBeNull()
        ->and(trim($heading->textContent))->toBe($spoken->subject, 'the heading is not the subject, so this proves nothing')
        // hasAttribute FIRST: getAttribute returns '' for an attribute that is
        // absent, so asserting the value alone cannot tell "declared unknown"
        // from "declared nothing" -- and the second one inherits German.
        ->and($heading->hasAttribute('lang'))->toBeTrue('the conversation subject inherits the dashboard language')
        ->and($heading->getAttribute('lang'))->toBe('', 'the conversation subject claims a language it cannot know');
});

test('a write finds its destination without a Referer header', function (): void {
    // `Referrer-Policy: no-referrer` -- set by browsers, embedded webviews and
    // reverse proxies -- strips the header while `redirect()->back()` still
    // lands on the conversation page, because that reads the SESSION's previous
    // URL. Resolving the locale from the header alone meant the redirect and
    // the locale disagreed about where the response was going.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['de'];

    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['status' => 'open', 'subject' => 'Datenpunkt referer', 'priority' => 'normal']);

    // `from()` seeds the session's previous URL; the header is sent as well, so
    // it is stripped explicitly here to leave only the session to answer.
    $this->actingAs($agent)
        ->from(route('dashboard.conversations.show', $conversation->support_code))
        ->withHeaders(['referer' => ''])
        ->post(route('dashboard.tickets.close', $ticket), ['resolution_note' => str_repeat('a', 4001)])
        ->assertSessionHasErrors('resolution_note');

    expect((string) session('errors')->getBag('default')->first('resolution_note'))
        ->toBe(__('validation.max.string', [
            'attribute' => __('validation.attributes.resolution_note', [], 'de'),
            'max' => 4000,
        ], 'de'));
});

test('every extracted cobrowse group renders translated', function (): void {
    // The leak guard cannot see inside this panel and will not until the
    // extraction finishes: the panel declares English, so an untranslated
    // string in it is announced English -- correctly -- and skipped.
    //
    // So each group that HAS been extracted is asserted here explicitly. The
    // list grows as the vocabulary lands, and when it covers the panel the
    // marker comes off and the ordinary guards take over.
    $world = conversationQueueLanguageWorld();
    $conversation = conversationQueueLanguageCobrowseSession()->conversation;

    $page = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent()
    );

    $extracted = [
        'cobrowse.consent.granted.label',
        'cobrowse.consent.granted.message',
        'cobrowse.actions.end',
        'cobrowse.resync.fulfilled.label',
        'cobrowse.resync.fulfilled.message',
        'cobrowse.timeline.requested.label',
        'cobrowse.timeline.responded.detail',
        'cobrowse.timeline.ignored.label',
        'cobrowse.freshness.fresh.label',
        'cobrowse.units.unknown_agent',
    ];

    // Plain keys only: a trans_choice entry resolves to its raw
    // '{1} one|[2,*] many' form through __(), which never matches a page.
    foreach ($extracted as $key) {
        $german = __($key, [], 'de');

        expect($german)->not->toContain('|', "{$key} is a trans_choice entry; this guard only reads plain keys");
        $english = __($key, [], 'en');

        expect($german)->not->toBe($key, "the key {$key} does not resolve in German");

        $this->assertStringContainsString($german, $page,
            "the extracted cobrowse key {$key} did not render in German");

        if ($german !== $english) {
            $this->assertStringNotContainsString($english, $page,
                "the English for {$key} is still on the German page");
        }
    }

    // A session that is still running must not show who stopped it. Asserted
    // before the session is ended below, because the alternative -- branching
    // on whether the ended-at text reads 'Still active' -- fails in the
    // direction that shows MORE than it should once that fallback is
    // translated, and no language assertion would notice.
    $this->assertStringNotContainsString(__('conversations.detail.cobrowse.stopped_by', [], 'de'), $page,
        'an active cobrowse session is showing who ended it');

    // The lifecycle's `ended_by` row only renders once a session has ended, so
    // nothing above reaches it -- a mutation that made endedByCopy() disagree
    // with endedByLabel() survived every other test in this file. Seventh
    // branch in this suite that needed a state before it could be guarded.
    CobrowseSession::query()
        ->where('conversation_id', $conversation->id)
        ->update(['status' => 'ended', 'ended_at' => now()->subMinute()]);

    $ended = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent()
    );

    $this->assertStringContainsString(__('conversations.detail.cobrowse.stopped_by', [], 'de'), $ended,
        'an ended cobrowse session does not show who stopped it');
    $this->assertStringContainsString(__('cobrowse.units.not_recorded', [], 'de'), $ended,
        'the ended-by fallback did not render in German');
    $this->assertStringNotContainsString(__('cobrowse.units.not_recorded', [], 'en'), $ended,
        'the English ended-by fallback is still on the German page');
});
test('the realtime handlers hard-code no copy of their own', function (): void {
    // This reads the source rather than the page, which is unusual and is the
    // point: the realtime handlers only run when a broadcast arrives, so no
    // request-based test reaches them, and a table-shape assertion proves the
    // words are AVAILABLE without proving the handler uses them. Two mutations
    // proved exactly that -- both reverted a field to English and both passed.
    //
    // Every word these handlers write comes from the label table, so any prose
    // literal among them is a bug by construction, whatever it currently says.
    //
    // Scoped to the handlers this page owns. The cobrowse handlers in the same
    // script still hard-code roughly forty English strings; that vocabulary is
    // its own change, and these names grow to cover them when it lands.
    $source = file_get_contents(resource_path('views/agent/conversations/show.blade.php'));

    $handlers = ['updateVisitorPresence', 'updateVisitorRead', 'fillElapsed', 'elapsedSince', 'updateSnapshotFreshness', 'transportHealthFromTelemetry', 'updateTransportHealth', 'recoveryFromSnapshotFreshness', 'updateSnapshotRecovery', 'droppedBatchPressure'];

    // The reply composer is a whole script rather than a few handlers, and the
    // announcement walker strips <script> before it looks at anything -- so no
    // amount of page-level auditing can see this copy. Checked at the source.
    $composer = file_get_contents(resource_path('views/agent/partials/reply-composer-script.blade.php'));

    // NOT expect()->toContain($x, $message): toContain is variadic, so the
    // message becomes a second required value and the assertion reports the
    // message itself as missing.
    $this->assertStringContainsString('data-reply-composer', $composer,
        'the composer script moved; this no longer reads it');

    $stripped = preg_replace('#//[^\n]*#', '', $composer);
    $stripped = preg_replace('/@json\([^)]*\)/', 'null', $stripped);

    preg_match_all("/'([^'\\\\\n]*)'/", $stripped, $composerFound);

    $composerProse = array_values(array_unique(array_filter(
        $composerFound[1],
        conversationQueueLanguageIsProse(...)
    )));

    expect($composerProse)->toBe([], 'the reply composer hard-codes copy instead of reading the catalogue');

    // A response message is only ours when the response is a 422. Anything else
    // -- a failed storage write, a 403, a 404 -- answers with a framework
    // exception message in English, and preferring it puts 'Not Found.' on a
    // German page. No request test reaches an upload's error branch.
    $this->assertStringContainsString('result.status === 422 && result.data && result.data.message', $composer,
        'the composer trusts a response message from a status that does not carry translated copy');

    // The upload chip is built in script, so no request test renders it. Its
    // name is the file the person chose, in whatever language they named it --
    // the same treatment the transcript's attachment names get server-side.
    $this->assertStringContainsString("nameEl.setAttribute('lang', '')", $composer,
        'the live attachment chip announces a filename in the dashboard language');

    // A filename is user data and goes into `String.replace` as a REPLACEMENT,
    // where `$&`, '$' followed by a backtick, and "$'" are read as
    // backreferences. A file called `$&.pdf` would name `:name.pdf` in the
    // aria-label. A function replacement has no such semantics.
    expect(preg_match("/\.replace\(':name', *(?!function)[a-zA-Z@]/", $composer))->toBe(0,
        'a filename is passed to String.replace as a replacement string, where $& expands');

    // A browser without Intl.RelativeTimeFormat still has perfectly good
    // timestamps. Treating the missing FORMATTER as missing DATA replaced a
    // real "seen 2 minutes ago" with "no visitor heartbeat yet" on every
    // event -- a different fact, not a degraded one.
    //
    // No request test reaches this: it is what the page does when a browser
    // API is absent. The contract is asserted at the source instead --
    // fillElapsed returns null rather than the fallback, and every caller
    // routes through a writer that skips a null.
    $page = file_get_contents(resource_path('views/agent/conversations/show.blade.php'));

    $this->assertStringContainsString("return elapsed === null ? null : template.replace(':elapsed', elapsed);", $page,
        'fillElapsed treats an unavailable formatter as missing data again');

    $this->assertStringContainsString('function setTextIfKnown(', $page,
        'the writer that skips an unknown value is gone');

    // Choosing "write a custom reply" has no body, so the template handler
    // returns early. The draft's language has to be cleared BEFORE that return
    // or the agent writes their own reply into an element still claiming the
    // previous template's language. No request test reaches a change event.
    $composerSource = file_get_contents(resource_path('views/agent/partials/reply-composer-script.blade.php'));
    $clear = strpos($composerSource, "templateTarget.setAttribute('lang', '')");
    $earlyReturn = strpos($composerSource, 'if (! body || ! templateTarget) {');

    expect($clear)->not->toBeFalse('the draft language is never cleared')
        ->and($earlyReturn)->not->toBeFalse('the early return moved; this guard no longer reads it')
        ->and($clear)->toBeLessThan($earlyReturn,
            'the draft language is cleared after the early return, so leaving a template keeps its language');

    // And nothing writes a fillElapsed result directly any more.
    expect(preg_match('/\.textContent = fillElapsed\(/', $page))->toBe(0,
        'a handler writes fillElapsed straight to the page, so a null lands as "null"');

    foreach ($handlers as $handler) {
        $open = strpos($source, 'function '.$handler.'(');

        // Without this the test passes by finding nothing -- which is how an
        // earlier version of it passed with the bug reintroduced.
        expect($open)->not->toBeFalse("the realtime handler {$handler} no longer exists under that name");

        $brace = strpos($source, '{', $open);
        $depth = 0;
        $close = null;

        for ($index = $brace; $index < strlen($source); $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;

                if ($depth === 0) {
                    $close = $index;

                    break;
                }
            }
        }

        expect($close)->not->toBeNull("could not find the end of {$handler}");

        $body = preg_replace('#//[^\n]*#', '', substr($source, $open, $close - $open + 1));

        preg_match_all("/'([^'\\\\\n]*)'/", $body, $found);

        $prose = array_values(array_unique(array_filter(
            $found[1],
            conversationQueueLanguageIsProse(...)
        )));

        expect($prose)->toBe([], "{$handler} hard-codes copy instead of reading the label table");
    }
});

test('nothing German is marked as English', function (): void {
    // The mirror of the cobrowse-panel finding. Marking translated copy as
    // English is the same defect pointing the other way, and it is the easier
    // one to introduce: a value that looks English in the source can be
    // `diffForHumans()`, which follows the page locale and returns German.
    //
    // Nothing caught this. Re-wrapping a locale-following timestamp in the
    // English marker passed every other test in this file.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $urls = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.tickets.index'),
        route('dashboard.conversations.show', $conversation->support_code),
    ];

    // Carbon's German relative time, which is what these fields actually emit.
    $germanElapsed = '/\bvor \d+ (Sekunde|Minute|Stunde|Tag|Woche|Monat|Jahr)/u';

    // And every German string we ship that differs from its English. A
    // translated value rendered inside a region marked English is the same
    // defect as an untranslated one left unmarked, and the date pattern above
    // only ever caught the timestamps -- it missed a catalogue value sitting
    // in the cobrowse panel, which the panel is entitled to declare English.
    $germanStrings = [];

    foreach (glob(lang_path('de/*.php')) ?: [] as $file) {
        $englishFile = lang_path('en/'.basename($file));

        if (! file_exists($englishFile)) {
            continue;
        }

        $flatten = function (array $values) use (&$flatten): array {
            $flat = [];

            foreach ($values as $key => $value) {
                $flat = is_array($value) ? array_merge($flat, $flatten($value)) : array_merge($flat, [$key => $value]);
            }

            return $flat;
        };

        $english = array_flip($flatten(require $englishFile));

        foreach ($flatten(require $file) as $value) {
            // Long enough to be a sentence rather than a word that could
            // coincide, and genuinely different from the English.
            if (is_string($value) && mb_strlen($value) > 8 && ! array_key_exists($value, $english)
                && ! str_contains($value, ':') && ! str_contains($value, '|')) {
                $germanStrings[] = $value;
            }
        }
    }

    $germanStrings = array_values(array_unique($germanStrings));

    expect($germanStrings)->not->toBe([]);

    foreach ($urls as $url) {
        $page = (string) $this->actingAs($world['agents']['de'])->get($url)->assertOk()->getContent();

        // Per text node with its EFFECTIVE language, not per element: a region
        // marked English legitimately contains translated fragments that reset
        // to the document language, and an element-level check reads those
        // nested resets as part of the English text.
        foreach (conversationQueueLanguageAnnouncements($page) as $announcement) {
            if (! str_starts_with($announcement['language'], 'en')) {
                continue;
            }

            expect(preg_match($germanElapsed, $announcement['text']))->toBe(0,
                "German announced as English at {$url}: \"{$announcement['text']}\"");

            foreach ($germanStrings as $german) {
                if (str_contains($announcement['text'], $german)) {
                    $this->fail("German announced as English at {$url}: \"{$german}\"");
                }
            }
        }
    }
});

test('no language marker is rendered inside an attribute', function (): void {
    // An element cannot go inside an attribute value. Wrapping a translated
    // string in <x-lang> is right in element content and catastrophic in an
    // attribute: the span's own quote closes the attribute early, and every
    // attribute after it is parsed as text. On an <iframe> that silently blanked
    // the cobrowse preview and hid it from the realtime script.
    //
    // A bulk edit that wraps every `__()` on a page will do this, because one
    // of them is always in a title. Attributes take their language from their
    // element, so `lang` goes on the element instead.
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();

        if (preg_match_all('/\b[a-z-]+="[^"]*<[a-z]/i', $contents, $found) > 0) {
            foreach ($found[0] as $match) {
                $offenders[] = $file->getRelativePathname().': '.$match;
            }
        }
    }

    expect($offenders)->toBe([], 'element markup rendered inside an attribute value');

    // Same trap, different container. An <option> takes TEXT content only, so a
    // nested <span lang=""> is dropped by the parser and the value inherits the
    // document language after all -- the markup looks right and does nothing.
    // The attribute belongs on the <option> itself.
    $inOptions = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Blade comments stripped first: this very guard is documented in a
        // comment that says the word `<option>`, and the regex matched from
        // inside it to the real closing tag.
        $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents()) ?? '';

        if (preg_match_all('/<option\b[^>]*>(.*?)<\/option>/is', $contents, $found) > 0) {
            foreach ($found[1] as $content) {
                if (preg_match('/<[a-z]/i', $content) === 1) {
                    $inOptions[] = $file->getRelativePathname().': '.trim(preg_replace('/\s+/', ' ', $content));
                }
            }
        }
    }

    expect($inOptions)->toBe([], 'element markup inside an <option>, which only takes text');
});

test('a cobrowse timestamp never travels without its language', function (): void {
    // Five branches hand-list their keys. Three got the language field and two
    // did not, so a German diffForHumans() value was marked English on exactly
    // the states a pending resync produces -- the common ones.
    //
    // `momentPair()` makes them inseparable; this asserts it stayed that way,
    // and drives every branch through a real session rather than a shape I
    // invented, so a branch I have not thought of is still covered.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $branches = [
        'pending' => ['requested_at' => now()->subSeconds(15)->toJSON(), 'fulfilled_at' => null],
        'delayed' => ['requested_at' => now()->subMinutes(4)->toJSON(), 'fulfilled_at' => null],
        'fulfilled' => ['requested_at' => now()->subMinutes(2)->toJSON(), 'fulfilled_at' => now()->toJSON()],
        'exhausted' => ['requested_at' => now()->subMinutes(2)->toJSON(), 'fulfilled_at' => null, 'attempts_exhausted_at' => now()->toJSON()],
        'expired' => ['requested_at' => now()->subHour()->toJSON(), 'fulfilled_at' => null],
    ];

    $state = app(CobrowseConsentState::class);
    $checked = 0;

    foreach ($branches as $name => $request) {
        $session->forceFill(['metadata' => [
            'resync_request' => array_merge(['id' => 'resync_'.$name, 'requested_by_name' => 'Support'], $request),
        ]])->save();

        $payload = $state->forConversation($conversation->fresh());

        $walk = function (array $node, string $path) use (&$walk, &$checked, $name): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value, $path.'.'.$key);

                    continue;
                }

                if (is_string($key) && str_ends_with($key, '_at') && is_string($value)) {
                    // A machine timestamp is not prose and has no language:
                    // `retry_at` is toJSON() for a data- attribute the script
                    // parses. Only the diffForHumans() values are announced.
                    if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1) {
                        continue;
                    }

                    $checked++;

                    // NOT toHaveKey($key, $message): its second argument is the
                    // expected VALUE, so the message is compared against the
                    // data and the test fails for the wrong reason -- or passes
                    // for one. Same trap as toContain()'s variadic second arg.
                    expect(array_key_exists($key.'_language', $node))->toBeTrue(
                        "{$name}: {$path}.{$key} is rendered without reporting its language"
                    );
                }
            }
        };

        $walk($payload, $name);
    }

    expect($checked)->toBeGreaterThan(0, 'no timestamp fields were produced, so this proves nothing');
});

test('every catalogue file answers the same set of keys', function (): void {
    // A key added to one language and not the other resolves to the raw key
    // string on the page -- German text with `tickets.flash.closed` in it. The
    // raw-key guard catches that only on states it renders; this catches it at
    // the source, for every key, including ones no test reaches.
    $missing = [];
    $identical = [];

    foreach (glob(lang_path('de/*.php')) ?: [] as $file) {
        $name = basename($file, '.php');
        $englishFile = lang_path('en/'.$name.'.php');

        // Laravel ships English for validation, so there is no en/ file to
        // compare against; every other catalogue must have both halves.
        if (! file_exists($englishFile)) {
            continue;
        }

        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $flat = [];

            foreach ($values as $key => $value) {
                $flat = array_merge($flat, is_array($value)
                    ? $flatten($value, $prefix.$key.'.')
                    : [$prefix.$key => $value]);
            }

            return $flat;
        };

        $english = $flatten(require $englishFile);
        $german = $flatten(require $file);

        foreach (array_keys($english) as $key) {
            if (! array_key_exists($key, $german)) {
                $missing[] = "de/{$name}: {$key}";
            } elseif ($german[$key] === $english[$key]) {
                $identical[] = "{$name}.{$key} = ".$english[$key];
            }
        }

        foreach (array_keys($german) as $key) {
            if (! array_key_exists($key, $english)) {
                $missing[] = "en/{$name}: {$key}";
            }
        }
    }

    expect($missing)->toBe([], 'a catalogue key exists in one language and not the other');

    // A translation identical to its English is usually a missed string rather
    // than a real cognate, so every one is named here deliberately. German
    // support vocabulary borrows most of these wholesale; `Cobrowse` is a
    // product term. This list is also the shortlist for the native-speaker
    // pass -- these are the words most likely to be wrong.
    $expectedCognates = [
        'cobrowse.transport.live.label = Live',
        'cobrowse.pressure.separator = , ',
        'cobrowse.units.milliseconds = :count ms',
        'conversations.columns.cobrowse = Cobrowse',
        'conversations.detail.headings.ticket = Ticket',
        'conversations.detail.tabs.ticket = Ticket',
        'conversations.detail.tabs.cobrowse = Cobrowse',
        'conversations.detail.cobrowse.heading = Cobrowse',
        'conversations.detail.cobrowse.url = URL',
        'conversations.detail.roles.agent = Agent',
        'conversations.detail.context.status = Status',
        'nav.items.tickets = Tickets',
        'profile.roles.agent = Agent',
        'profile.details.name = Name',
        'tickets.document_title = Tickets',
        'tickets.columns.status = Status',
        'tickets.columns.labels = Labels',
        'tickets.columns.label = Label',
        'tickets.priorities.normal = Normal',
        'tickets.chips.status = Status: :value',
        'tickets.chips.label = Label: :value',
        'tickets.row.actor_system = System',
        'ticket_detail.common.status = Status',
        'ticket_detail.common.agent = Agent',
        'ticket_detail.common.system = System',
        'ticket_detail.external.url = URL',
        'ticket_detail.details.labels = Labels',
        'ticket_detail.artifacts.labels = Labels',
        'reply_templates.list.column_status = Status',
        'reply_templates.manage.name = Name',
        'ticket_labels.list.heading = Labels',
        'ticket_labels.list.column_label = Label',
        'ticket_labels.list.column_slug = Slug',
        'api_tokens.list.column_name = Name',
        'api_tokens.list.column_token = Token',
        'account_audit.references.system = System',
        'account_audit.references.cobrowse = Cobrowse',
        'account.activity.system = System',
        'account.create.name = Name',
        'account.agents.columns.agent = Agent',
        'account.agents.columns.status = Status',
        'operator.scanning.driver = Scanner',
        'operator.mail.transport = Transport',
        'operator.backups.history.status = Status',
        'operator.dashboard.overview.status = Status',
        'operator.dashboard.budget.units.milliseconds = :count ms',
        'operator.dashboard.activity.system = System',
        'operator.dashboard.activity.details.transport = Transport',
        'operator.dashboard.activity.details.scanner = Scanner',
        'operator_break_glass.grant.tickets.heading = Tickets',
        'operator_break_glass.conversation.senders.system = System',
        'operator_break_glass.ticket.record.status = Status',
        'operator_break_glass.values.not_set = —',
        // An em dash. Punctuation rather than a word, and in the catalogue so a
        // language that prefers a different dash can say so.
        'sites_live.duration.unknown = —',
        'visitors.snapshot.tickets = Tickets',
        'visitors.history.tickets = Tickets',
    ];

    expect(array_values(array_diff($identical, $expectedCognates)))->toBe([],
        'a German string is identical to its English -- a real cognate, or a missed translation?');
});

test('an action on the detail page answers in the agent language', function (): void {
    // The page translates `session('status')`, so a controller that flashes an
    // English literal puts English feedback on a German page. Ticket actions
    // reach here through `redirect()->back()`, so they are this page's flashes
    // even though they belong to another controller.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['de'];

    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['status' => 'open', 'subject' => 'Datenpunkt flash', 'priority' => 'normal']);

    $this->actingAs($agent)
        ->from(route('dashboard.conversations.show', $conversation->support_code))
        ->post(route('dashboard.tickets.close', $ticket))
        ->assertRedirect();

    $flashed = session('status');

    expect($flashed)->toBeString()->not->toBe('');

    // The flash must be a key, and that key must answer in German here.
    expect(__($flashed, [], 'de'))->not->toBe($flashed, "the flashed status '{$flashed}' is not a catalogue key")
        ->and(__($flashed, [], 'de'))->not->toBe(__($flashed, [], 'en'));
});

test('the attachment endpoint answers in the agent language', function (): void {
    // The composer prefers the response's own message over its local fallback,
    // so an endpoint outside EXTRACTED_ROUTES puts English into a German page
    // on something as ordinary as an oversized file.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    expect(DashboardLanguage::EXTRACTED_ROUTES)
        ->toContain('dashboard.conversations.attachments.store');

    $response = $this->actingAs($world['agents']['de'])
        ->postJson(route('dashboard.conversations.attachments.store', $conversation->support_code), []);

    $response->assertStatus(422);

    $message = (string) ($response->json('message') ?? '');

    expect($message)->not->toBe('')
        ->and($message)->not->toContain('field is required')
        ->and($message)->not->toContain('must be a file');
});

test('the widget attachment endpoint answers the visitor, not the install', function (): void {
    // The test above is why the shared upload service began resolving copy at
    // all. The widget shares that service, and the visitor's language is the
    // SITE's setting -- it has nothing to do with the language the operator
    // reads their own dashboard in.
    //
    // So on a German install, an English site's visitor was told in German that
    // their file type was not allowed.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $site = $conversation->site;
    $visitor = $conversation->visitor;

    // The install speaks German. Nothing below should care.
    config(['app.locale' => 'de']);
    app()->setLocale('de');

    $cases = [
        ['en', 'en', 'a site pinned to English'],
        ['de', 'de', 'a site pinned to German'],
        // Unpinned means the widget follows the visitor's BROWSER, which the
        // server never sees. English is the honest answer, and the same one
        // the widget falls back to -- never the install's German.
        [null, 'en', 'a site that pins nothing'],
    ];

    foreach ($cases as [$pinned, $expected, $label]) {
        $settings = $site->settings ?? [];
        $settings['locale'] = $pinned;
        $site->forceFill(['settings' => $settings])->save();

        $response = $this->postJson(
            route('conversations.attachments.store', $conversation->support_code),
            [
                'site_public_key' => $site->public_key,
                'anonymous_id' => $visitor->anonymous_id,
                'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
                'file' => UploadedFile::fake()->create('payload.exe', 8),
            ],
        );

        $response->assertStatus(422);

        expect((string) $response->json('errors.file.0'))
            ->toBe(__('composer.rejected.type', [], $expected), "{$label} was not answered in {$expected}");

        // And the same for a rejection the FRAMEWORK writes. An oversized file
        // never reaches the upload service: `validate()` stops it first, so no
        // catch block can translate it. That rule ran before the site had even
        // been resolved, which is why this case needs its own upload rather
        // than trusting the one above.
        $oversized = $this->postJson(
            route('conversations.attachments.store', $conversation->support_code),
            [
                'site_public_key' => $site->public_key,
                'anonymous_id' => $visitor->anonymous_id,
                'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
                'file' => UploadedFile::fake()->create(
                    'huge.txt',
                    (int) ceil(((int) config('wayfindr.attachments.max_file_bytes')) / 1024) + 64,
                ),
            ],
        );

        $oversized->assertStatus(422);

        $message = (string) $oversized->json('errors.file.0');

        expect($message)->not->toBe('', "{$label} produced no oversize message");

        // Asserted by what the message IS, not by what it is not: a German
        // message on an English site and an English one on a German site are
        // the same defect pointing opposite ways.
        $germanShape = str_contains($message, 'höchstens') && str_contains($message, 'Kilobyte');

        expect($germanShape)->toBe($expected === 'de',
            "{$label} got the oversize rejection in the wrong language: {$message}");
    }
});

test('the shared attachment services never resolve copy themselves', function (): void {
    // The structural half of the test above. These services are called by the
    // dashboard, by the widget and by a queue worker processing inbound mail --
    // three readers, three languages, and one of them is nobody at all. A
    // service that resolves a sentence has answered a question only the calling
    // surface can answer, and it will be wrong for two of the three.
    //
    // `AttachmentRejected` is exempt because it resolves nothing on its own:
    // its locale is passed in by whichever surface is replying.
    $exempt = ['AttachmentRejected.php'];

    foreach (glob(app_path('Support/Attachments/*.php')) ?: [] as $file) {
        if (in_array(basename($file), $exempt, true)) {
            continue;
        }

        // Comments first -- this guard's own explanation says `__(`, and a
        // guard that matches its own documentation reports nothing forever.
        $source = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));

        expect(preg_match('/(?<![\w$>])__\s*\(/', (string) $source))->toBe(0,
            basename($file).' resolves copy inside a service three surfaces share');
    }
});

test('the widget can say which language it actually resolved', function (): void {
    // The site default is the only one of the widget's four inputs the server
    // can see. The widget resolves host page -> browser -> site default ->
    // English, so a German-default site showing an English host-page override,
    // or a German browser on an unpinned site, had the panel in one language
    // and its upload errors in the other.
    //
    // So the widget tells us what it resolved, and we take it when it names a
    // catalogue we ship.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $site = $conversation->site;
    $visitor = $conversation->visitor;

    config(['app.locale' => 'de']);
    app()->setLocale('de');

    $upload = function (?string $pinned, ?string $requested) use ($site, $visitor, $conversation) {
        $settings = $site->settings ?? [];
        $settings['locale'] = $pinned;
        $site->forceFill(['settings' => $settings])->save();

        return $this->postJson(
            route('conversations.attachments.store', $conversation->support_code),
            array_filter([
                'site_public_key' => $site->public_key,
                'anonymous_id' => $visitor->anonymous_id,
                'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
                'locale' => $requested,
                'file' => UploadedFile::fake()->create('payload.exe', 8),
            ], fn ($value): bool => $value !== null),
        );
    };

    // A host page that overrides a German site to English.
    expect((string) $upload('de', 'en')->json('errors.file.0'))
        ->toBe(__('composer.rejected.type', [], 'en'),
            'the widget said English and was answered in the site default');

    // A German browser on a site that pins nothing.
    expect((string) $upload(null, 'de')->json('errors.file.0'))
        ->toBe(__('composer.rejected.type', [], 'de'),
            'the widget said German and was answered in English');

    // Something we do not ship falls back rather than failing. A locale is a
    // question we answer, not an error the visitor should be shown -- and
    // validating it would mean writing a message before knowing its language.
    expect((string) $upload('de', 'kl')->json('errors.file.0'))
        ->toBe(__('composer.rejected.type', [], 'de'),
            'an unsupported locale was not ignored in favour of the site default');
});

test('the first conversation is validated in the visitor language too', function (): void {
    // The endpoint a NEW visitor hits, whose intake rules are the first words
    // we ever write to them -- and they are written by the framework, so no
    // catch block reaches them. It resolves the site before validating (the
    // intake rules need it), which is exactly where the language belongs.
    $world = conversationQueueLanguageWorld();
    $site = $world['site'];

    config(['app.locale' => 'de']);
    app()->setLocale('de');

    // Ask for an email so there is a rule a visitor can fail.
    $settings = $site->settings ?? [];
    $settings['locale'] = 'de';
    $settings['intake'] = ['enabled' => true, 'fields' => ['email' => 'required']];
    $site->forceFill(['settings' => $settings])->save();

    $start = function (?string $requested) use ($site) {
        return $this->postJson(route('conversations.store'), array_filter([
            'site_public_key' => $site->public_key,
            'anonymous_id' => 'anon-first-'.($requested ?? 'none'),
            'locale' => $requested,
            'body' => 'Hallo',
            'visitor_email' => 'not-an-email',
        ], fn ($value): bool => $value !== null));
    };

    $message = function ($response): string {
        $errors = $response->json('errors') ?? [];

        expect($errors)->not->toBe([],
            'the intake rule did not reject, so this proves nothing: '.$response->status());

        return (string) (reset($errors)[0] ?? '');
    };

    // Asserted on a word only one of the catalogues has, so the attribute name
    // Laravel builds from the field does not decide whether this passes.
    $germanShape = fn (string $text): bool => str_contains($text, 'gültige');

    // The host page overrides this German site to English.
    expect($germanShape($message($start('en'))))->toBeFalse(
        'a new visitor on an English override was answered in the site language');

    expect($germanShape($message($start('de'))))->toBeTrue(
        'a new visitor reading German was answered in English');
});

test('a framework rejection never shows the agent a column name', function (): void {
    // The custom rejections on this endpoint have hand-written German. The
    // FRAMEWORK ones interpolate `:attribute`, and an unnamed field puts the
    // column into the middle of a German sentence: "body darf höchstens 4000
    // Zeichen lang sein." Ordinary path -- the composer has no maxlength, so
    // pasting a long reply reaches it.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $response = $this->actingAs($world['agents']['de'])
        ->from(route('dashboard.conversations.show', $conversation->support_code))
        ->post(route('dashboard.conversations.messages.store', $conversation->support_code), [
            'body' => str_repeat('a', 4001),
        ]);

    $response->assertSessionHasErrors('body');

    $message = (string) session('errors')->getBag('default')->first('body');

    expect($message)->not->toBe('', 'the length rule did not reject, so this proves nothing');

    // German, and naming the field the way the interface does.
    $this->assertStringContainsString(__('validation.attributes.body', [], 'de'), $message,
        'the reply field is not named in German');
    $this->assertStringNotContainsString('body', $message,
        "the agent was shown a column name: {$message}");
});

test('every field a German page can submit has a German name', function (): void {
    // The structural half. The test above only reaches `body`; the next field
    // added to this endpoint would be just as raw and just as invisible.
    $source = (string) file_get_contents(app_path('Http/Controllers/AgentConversationController.php'));

    $matched = preg_match(
        '/public function storeMessage\(.*?\$request->validate\(\[(.*?)\]\);/s',
        $source,
        $found
    );

    expect($matched)->toBe(1, 'storeMessage moved; this no longer reads its rules');

    preg_match_all("/'([a-z_]+)(?:\.\*)?' => \[/", $found[1], $fields);

    expect($fields[1])->not->toBe([], 'no validated fields were extracted, so this proves nothing');

    $attributes = require lang_path('de/validation.php');

    foreach (array_unique($fields[1]) as $field) {
        expect($attributes['attributes'] ?? [])->toHaveKey($field);
    }
});

test('a subject of "0" is a subject, not a missing one', function (): void {
    // PHP reads the perfectly good subject "0" as false, so every truthiness
    // test on it told the agent the visitor had written none -- in the queue,
    // in the detail heading, and in that heading's language marker, which then
    // announced the visitor's own words as our copy.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill(['subject' => '0'])->save();

    $queue = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$queue);
    $xpath = new DOMXPath($document);

    // This conversation's own row, so another untitled conversation in the
    // fixture cannot answer for it either way.
    $link = $xpath->query('//a[contains(@href, "'.$conversation->support_code.'")]')->item(0);

    expect($link)->not->toBeNull('the conversation did not render in the queue')
        ->and(trim($link->textContent))->toBe('0', 'the queue called a subject of "0" untitled');

    $detail = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $this->assertStringNotContainsString(__('conversations.detail.untitled', [], 'de'), $detail,
        'the detail heading called a subject of "0" untitled');

    // And it is still marked as the visitor's words rather than as ours.
    $detailDocument = new DOMDocument;
    @$detailDocument->loadHTML('<?xml encoding="utf-8"?>'.$detail);

    $unknown = [];

    foreach ((new DOMXPath($detailDocument))->query('//*[@lang=""]') as $node) {
        $unknown[] = trim($node->textContent);
    }

    expect($unknown)->toContain('0');
});

test('every template-backed draft stops claiming the template language once the agent types', function (): void {
    // Choosing an English helper marks the textarea English so the inserted
    // text is announced correctly. The moment the agent edits it the words are
    // theirs. This belongs to the picker target rather than the reply form so
    // it covers both reply composers and the ticket's internal-note helper.
    //
    // Source-level: the announcement walker strips <script> before it looks at
    // anything, so no rendered page can show this.
    $composer = (string) file_get_contents(
        resource_path('views/agent/partials/reply-composer-script.blade.php')
    );

    $stripped = (string) preg_replace('#//[^\n]*#', '', $composer);

    // The target input handler, closed at ITS OWN indentation. Closing on a
    // shallower `});` can swallow an unrelated language reset later in the
    // script and leave this passing when the template reset is gone.
    $matched = preg_match(
        "/templateTarget\.addEventListener\('input', function \(\) \{(.*?)\n                \}\);/s",
        $stripped,
        $handler
    );

    expect($matched)->toBe(1, 'the template-target input handler moved; this no longer reads it');

    $this->assertStringContainsString("setAttribute('lang', '')", $handler[1],
        'editing a template-backed draft leaves the template language on its target');

    $ticketWorkspace = (string) file_get_contents(resource_path('views/agent/tickets/show.blade.php'));

    $this->assertStringContainsString(
        'name="note_template" data-template-picker data-target="#body"',
        $ticketWorkspace,
        'the internal-note helper is not wired to the template-target language reset'
    );
});

test('a write answers in the language of the page it renders back to', function (): void {
    // A linked-ticket action is submitted from BOTH the conversation panel and
    // the ticket page, and its validation runs before the redirect. A route-name
    // allowlist cannot say which page owns the response. The language belongs
    // to whichever surface actually renders the answer.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['de'];

    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['status' => 'open', 'subject' => 'Datenpunkt locale', 'priority' => 'normal']);

    $tooLong = ['resolution_note' => str_repeat('a', 4001)];

    $errorFor = function (string $from) use ($agent, $ticket, $tooLong): string {
        $this->actingAs($agent)->from($from)
            ->post(route('dashboard.tickets.close', $ticket), $tooLong)
            ->assertSessionHasErrors('resolution_note');

        return (string) session('errors')->getBag('default')->first('resolution_note');
    };

    // Submitted from the conversation panel, which IS extracted.
    $germanError = $errorFor(route('dashboard.conversations.show', $conversation->support_code));

    expect($germanError)->toBe(__('validation.max.string', [
        'attribute' => __('validation.attributes.resolution_note', [], 'de'),
        'max' => 4000,
    ], 'de'));

    // Two tabs: the session's previous URL is the conversation page (the most
    // recent navigation anywhere), but THIS request came from a still-English
    // site page.
    // The redirect follows the header, so the locale must too -- reading the
    // session first answered in German on an English page.
    $this->actingAs($agent)
        ->from(route('dashboard.conversations.show', $conversation->support_code))
        ->withHeaders(['referer' => route('dashboard.sites.show', $world['site'])])
        ->post(route('dashboard.tickets.close', $ticket), $tooLong)
        ->assertSessionHasErrors('resolution_note');

    expect((string) session('errors')->getBag('default')->first('resolution_note'))
        ->not->toBe(__('validation.max.string', [
            'attribute' => __('validation.attributes.resolution_note', [], 'de'),
            'max' => 4000,
        ], 'de'), 'a stale session URL outvoted the Referer this request actually carried');

    // Submitted from a page that is not extracted, so the same endpoint
    // answers in English -- the page that will render it.
    $englishError = $errorFor(route('dashboard.sites.show', $world['site']));

    expect($englishError)->not->toBe('')
        ->and($englishError)->not->toBe($germanError)
        ->and($englishError)->toContain('4000');
});

test('ticket workspace writes validate their fields in the agent language', function (): void {
    // These routes belong only to the ticket workspace, so each is named in
    // EXTRACTED_ROUTES. Exercising both the ticket itself and the integration
    // forms keeps their field names from leaking raw database vocabulary into
    // an otherwise German validation sentence.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['status' => 'open', 'subject' => 'Datenpunkt validation', 'priority' => 'normal']);
    $agent = $world['agents']['de'];
    $from = route('dashboard.tickets.show', $ticket);

    $requiredError = function (string $method, string $route, array $payload, string $field) use ($agent, $from): string {
        $this->actingAs($agent)
            ->from($from)
            ->call($method, $route, $payload)
            ->assertSessionHasErrors($field);

        return (string) session('errors')->getBag('default')->first($field);
    };

    expect($requiredError('PUT', route('dashboard.tickets.update', $ticket), [
        'subject' => '',
        'priority' => 'normal',
    ], 'subject'))->toBe(__('validation.required', [
        'attribute' => __('validation.attributes.subject', [], 'de'),
    ], 'de'));

    expect($requiredError('POST', route('dashboard.tickets.external-links.store', $ticket), [
        'provider' => 'github',
    ], 'project_key'))->toBe(__('validation.required', [
        'attribute' => __('validation.attributes.project_key', [], 'de'),
    ], 'de'));

    expect($requiredError('POST', route('dashboard.tickets.external-issues.github.store', $ticket), [], 'site_external_issue_project_id'))
        ->toBe(__('validation.required', [
            'attribute' => __('validation.attributes.site_external_issue_project_id', [], 'de'),
        ], 'de'));

    $this->actingAs($agent)
        ->from($from)
        ->post(route('dashboard.tickets.external-links.store', $ticket), [
            'provider' => 'github',
            'project_key' => 'wayfindr/project',
            'url' => 'https://github.example.test/wayfindr/project/issues/1',
            'sync_status' => 'linked',
        ])
        ->assertSessionHas('status', 'ticket_detail.flash.external_link_added');

    $this->actingAs($agent)
        ->get($from)
        ->assertOk()
        ->assertSee('Externe Verknüpfung hinzugefügt.')
        ->assertDontSee('External link added.');
});

test('authored subject changes keep their own language boundary in ticket activity', function (): void {
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['subject' => 'Problem gelöst']);
    $agent = $world['agents']['de'];

    AuditEvent::query()->create([
        'account_id' => $world['account']->id,
        'site_id' => $world['site']->id,
        'actor_type' => $agent->getMorphClass(),
        'actor_id' => $agent->id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'action' => 'ticket.updated',
        'metadata' => [
            'changes' => [
                'subject' => [
                    'old' => 'Checkout & billing',
                    'new' => 'Problem gelöst',
                ],
            ],
        ],
        'occurred_at' => now(),
    ]);

    $html = (string) $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->getContent();

    // The update appears in both the unified timeline and the activity tab.
    // Its sentence is German, while each authored value declares HTML's
    // unknown language instead of inheriting German pronunciation rules.
    expect(substr_count($html, '<span lang="">Checkout &amp; billing</span>'))->toBe(2)
        ->and(substr_count($html, '<span lang="">Problem gelöst</span>'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('Betreff von')
        ->and($html)->toContain('geändert');
});

test('authored label names keep their own language boundary in ticket activity', function (): void {
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['subject' => 'Label test']);
    $agent = $world['agents']['de'];

    AuditEvent::query()->create([
        'account_id' => $world['account']->id,
        'site_id' => $world['site']->id,
        'actor_type' => $agent->getMorphClass(),
        'actor_id' => $agent->id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'action' => 'ticket.label_added',
        'metadata' => ['label_name' => 'Billing & Rückgabe'],
        'occurred_at' => now(),
    ]);

    AuditEvent::query()->create([
        'account_id' => $world['account']->id,
        'site_id' => $world['site']->id,
        'actor_type' => $agent->getMorphClass(),
        'actor_id' => $agent->id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'action' => 'ticket.label_removed',
        'metadata' => ['label_name' => 'VIP & Rücksendung'],
        'occurred_at' => now()->addSecond(),
    ]);

    $html = (string) $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->getContent();

    // The event appears in both the unified timeline and the activity tab.
    expect(substr_count($html, '<span lang="">Billing &amp; Rückgabe</span>'))->toBe(2)
        ->and(substr_count($html, '<span lang="">VIP &amp; Rücksendung</span>'))->toBe(2)
        ->and(substr_count($html, 'Label hinzugefügt:'))->toBe(2)
        ->and(substr_count($html, 'Label entfernt:'))->toBe(2);
});

test('the ticket browser title stays in the dashboard language', function (): void {
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['subject' => 'Checkout & billing']);

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<title>Ticket-Nr. '.$ticket->id.'</title>')
        ->and($html)->not->toContain('<title lang=""')
        // The authored subject still owns the visible heading boundary.
        ->and($html)->toContain('<h1 lang="">Checkout &amp; billing</h1>');
});

test('external failure project keys keep their own language boundary', function (): void {
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();
    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['subject' => 'External issue']);

    TicketExternalLink::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($ticket)
        ->create([
            'provider' => 'github',
            'project_key' => 'Billing & Rückgabe',
            'sync_status' => ExternalIssueSyncStatus::FAILED,
            'last_synced_at' => now(),
        ]);

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<span lang="">Billing &amp; Rückgabe</span>')
        ->and($html)->toContain('GitHub konnte')
        ->and($html)->toContain('nicht synchronisieren.');
});

test('a reply template says what language its body is in', function (): void {
    // The body is what the VISITOR receives, not chrome. A built-in is English
    // and says so. A managed one is written by the account in whatever language
    // it works in, so it reports `lang=""` -- HTML's "unknown". Claiming either
    // English or the agent's language would be a guess a screen reader acts on.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $builtIn = AgentReplyTemplate::options();

    expect($builtIn)->not->toBe([]);

    foreach ($builtIn as $key => $template) {
        expect($template['body_language'] ?? null)->toBe(DashboardLanguage::FALLBACK,
            "the built-in template {$key} does not declare its body language");
    }

    $page = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    // The picker carries it too, because selecting a template rewrites the
    // textarea and the draft stops being the agent's language at that moment.
    expect($page)->toContain('data-body-lang="en"');

    // A validation failure re-renders this page with the draft restored, and no
    // change event ever fires -- so the textarea's language has to be right in
    // the MARKUP, not only in the handler that sets it on selection.
    //
    // Which language depends on whose words are in the box, and the picker
    // staying selected does not answer that. This used to assert English for a
    // draft that had been EDITED -- its fixture body was a truncation of the
    // template's -- so it required the bug it was written to prevent.
    $restoredDraftLanguage = function (string $body) use ($world, $conversation): string {
        $html = (string) $this->actingAs($world['agents']['de'])
            ->from(route('dashboard.conversations.show', $conversation->support_code))
            ->withSession(['_old_input' => ['body' => $body, 'reply_template' => 'looking_into_it']])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent();

        $restored = new DOMDocument;
        @$restored->loadHTML('<?xml encoding="utf-8"?>'.$html);

        $draft = (new DOMXPath($restored))->query('//textarea[@data-reply-body]')->item(0);

        expect($draft)->not->toBeNull('no reply draft rendered, so this proves nothing')
            ->and($draft->hasAttribute('lang'))->toBeTrue('the restored draft inherits the dashboard language');

        return $draft->getAttribute('lang');
    };

    // Untouched: still the template's words, so still the template's language.
    expect($restoredDraftLanguage($builtIn['looking_into_it']['body']))->toBe(DashboardLanguage::FALLBACK,
        'an unedited built-in template body does not declare the language it is actually in');

    // Edited: the agent's words now. The handler clears the marker at the first
    // keystroke, and re-rendering must not put it back.
    expect($restoredDraftLanguage('Thanks for the update.'))->toBe('',
        'an edited draft is still announced as the template language');

    // A managed template reports unknown rather than guessing.
    ReplyTemplate::factory()->for($world['account'])->create([
        'name' => 'Datenpunkt Vorlage',
        'body' => 'Wir prüfen das und melden uns gleich.',
        'is_active' => true,
    ]);

    $managed = app(ReplyTemplateOptions::class)->forAgent($world['agents']['de']);

    expect($managed)->not->toBe([]);

    foreach ($managed as $key => $template) {
        expect($template['body_language'] ?? null)->toBe('',
            "the managed template {$key} claims a language it cannot know");
        expect($template['label_language'] ?? null)->toBe('',
            "the managed template {$key} claims to know its own NAME's language");
    }

    // And the picker itself, rendered with that template in place -- the option
    // is what a screen reader reads when the agent opens the menu, and it is a
    // different element from the preview that was already marked.
    $withManaged = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $pickerDocument = new DOMDocument;
    @$pickerDocument->loadHTML('<?xml encoding="utf-8"?>'.$withManaged);

    $option = null;

    foreach ((new DOMXPath($pickerDocument))->query('//select[@data-template-picker]/option') as $candidate) {
        if (str_contains($candidate->textContent, 'Datenpunkt Vorlage')) {
            $option = $candidate;
        }
    }

    expect($option)->not->toBeNull('the managed template is not in the picker, so this proves nothing')
        ->and($option->hasAttribute('lang'))->toBeTrue('a managed template name inherits the dashboard language')
        ->and($option->getAttribute('lang'))->toBe('', 'a managed template name claims a language it cannot know');
});

test('every label the page reads is a label the controller supplies', function (): void {
    // A missing entry here is not a language bug. `realtimeLabels.cobrowseUnits
    // .applied.replace(...)` on an undefined value is a TypeError, so the whole
    // handler dies -- and in the preview's case a SUCCESSFUL refresh reported
    // itself as a failure. I shipped exactly that: two keys consumed, neither
    // supplied, every test green.
    //
    // Nothing executes this script in the suite, so the two halves are compared
    // instead: every dotted path the source reads must resolve in the table the
    // controller renders.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'language-test-key',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    $page = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    preg_match('/var realtimeLabels = (\{.*?\});/s', $page, $found);

    expect($found)->not->toBe([], 'no realtime label table rendered');

    $labels = json_decode(html_entity_decode($found[1], ENT_QUOTES), true);

    expect($labels)->toBeArray();

    // Dotted paths only -- a bracket lookup is dynamic and cannot be resolved
    // from the source, and those already fall back with `||`.
    preg_match_all('/realtimeLabels((?:\.[A-Za-z_][A-Za-z0-9_]*)+)/', $page, $paths);

    $missing = [];

    foreach (array_unique($paths[1]) as $path) {
        $node = $labels;

        foreach (array_filter(explode('.', $path)) as $segment) {
            // Once the path resolves to a string the rest is a method call on
            // it -- `.replace`, `.toLocaleString`. The label itself is what
            // this checks, not what the script then does with it.
            if (is_string($node)) {
                break;
            }

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                $missing[] = 'realtimeLabels'.$path;

                continue 2;
            }

            $node = $node[$segment];
        }
    }

    expect($missing)->toBe([], 'the page reads a label the controller never supplies, which is a TypeError at runtime');
});

test('the cobrowse states the fixture does not reach are translated too', function (): void {
    // Two branches the standing fixture cannot show: a page the widget could
    // not name, and a resync still pending (the fixture's is fulfilled). Both
    // survived a mutation because neither renders in the default state.
    //
    // The untitled fallback is the more interesting one -- it is OUR copy
    // wearing the visitor's clothes, and marking it `lang=""` both mispronounces
    // it and hides it from the leak guard, which skips unknown-language text by
    // design. A wrong marker is not a smaller mistake than a missing one.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;
    $metadata['snapshot']['title'] = null;
    $metadata['page_state']['title'] = null;
    $metadata['resync_request'] = [
        'id' => 'resync-pending-fixture',
        'requested_by_name' => 'Support',
        'requested_at' => now()->subSeconds(30)->toJSON(),
        'fulfilled_at' => null,
    ];

    $session->forceFill(['metadata' => $metadata])->save();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $page = conversationQueueLanguageVisibleText($html);

    $this->assertStringContainsString(__('cobrowse.units.untitled_page', [], 'de'), $page,
        'the untitled-page fallback did not render in German');
    $this->assertStringNotContainsString(__('cobrowse.units.untitled_page', [], 'en'), $page,
        'the English untitled-page fallback is still on the German page');

    // The retry timer's copy travels in data- attributes, which the
    // announcement walker does not read -- it only knows the accessible ones.
    // Asserted directly.
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

    $form = (new DOMXPath($document))->query('//form[@data-resync-retry-form]')->item(0);

    expect($form)->not->toBeNull('no pending retry form rendered, so this proves nothing');

    foreach ([
        'data-retry-label' => 'cobrowse.labels.request_another_snapshot',
        'data-retry-ready-help' => 'cobrowse.labels.retry_ready_help',
        'data-retry-ready-recovery' => 'cobrowse.labels.retry_ready_recovery',
    ] as $attribute => $key) {
        expect($form->getAttribute($attribute))->toBe(__($key, [], 'de'),
            "{$attribute} is not the German the timer will write");
    }
});

test('a transport with no reports yet says so in German', function (): void {
    // A third branch the standing fixture cannot show, and the one that got
    // through: between consent and the first transport heartbeat the value was
    // the English literal "Not reported", explicitly marked `lang="en"`. The
    // panel's own no-English guard therefore read it as deliberate and passed.
    //
    // The realtime handler already wrote German for this state, so the agent
    // saw English only until the first update landed -- and that update assigns
    // `textContent` to this element, which would have destroyed the marker
    // anyway. Two languages for one state, decided by timing.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;
    unset(
        $metadata['telemetry']['reported_at'],
        $metadata['page_state']['reported_at'],
        $metadata['snapshot']['reported_at'],
        $metadata['mutations']['last_reported_at'],
    );

    $session->forceFill(['metadata' => $metadata])->save();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $field = $xpath->query('//*[@data-cobrowse-transport-last-report]')->item(0);

    expect($field)->not->toBeNull('the transport field did not render, so this proves nothing');

    expect(trim($field->textContent))->toBe(__('cobrowse.units.not_reported', [], 'de'),
        'the not-reported transport state is not in the agent\'s language');

    // And it carries no language of its own. The translated value belongs to
    // the page exactly as the timestamp it stands in for does, so there is
    // nothing left here for the realtime handler to overwrite.
    expect($field->hasAttribute('lang'))->toBeFalse('the transport field still declares a language');

    foreach ($xpath->query('.//*[@lang]', $field) as $marked) {
        $this->fail('the transport field still marks a fragment: "'.trim($marked->textContent).'"');
    }
});

test('a partial page-state report is still German', function (): void {
    // The panel's English boundary used to cover these. A visitor page that
    // reports SOME of itself is an ordinary state -- a browser that gave a
    // title but no URL, a report before the first scroll -- and every one of
    // those fields fell back to the literal "Not reported", which then
    // inherited German and was announced in it.
    //
    // The leak guard cannot see these: each is a value, and a string with a
    // digit in it is skipped as data.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;

    // Everything the visitor's browser could decline to report, declined at
    // once -- while the title stays, so the panel still renders.
    unset(
        $metadata['page_state']['page_url'],
        $metadata['page_state']['viewport_width'],
        $metadata['page_state']['viewport_height'],
        $metadata['page_state']['scroll_x'],
        $metadata['page_state']['scroll_y'],
        $metadata['snapshot']['page_url'],
        $metadata['mutations']['last_page_url'],
    );

    $session->forceFill(['metadata' => $metadata])->save();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $page = conversationQueueLanguageVisibleText($html);

    $this->assertStringContainsString(__('cobrowse.units.not_reported', [], 'de'), $page,
        'the not-reported fallback did not render in German');
    $this->assertStringNotContainsString(__('cobrowse.units.not_reported', [], 'en'), $page,
        'an English not-reported literal is still on the German page');
});

test('a reported byte count is German too', function (): void {
    // "500 bytes" is a value with an English word welded to it, and the leak
    // guard skips it for the digit. Every other count on this panel renders
    // through the catalogue from a `_value`; these two rendered the model's
    // own sentence, so German agents read "Bytes" as "bytes".
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;
    $metadata['telemetry']['payload_bytes'] = 2048;
    $metadata['telemetry']['max_payload_bytes'] = 4096;
    $session->forceFill(['metadata' => $metadata])->save();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $page = conversationQueueLanguageVisibleText($html);

    // Both halves of this pair used to compute their expectation with
    // `number_format()`, which froze the en-US separator into a test about
    // language. It could not see the defect it was standing next to: the
    // German page really did say `2,048 Bytes`, and the negative half kept
    // passing for the wrong reason -- the English NOUN was absent, so nobody
    // looked at the number.
    $this->assertStringContainsString(__('cobrowse.units.bytes', ['count' => '2.048'], 'de'), $page,
        'the payload size is not in the agent language');
    $this->assertStringNotContainsString(__('cobrowse.units.bytes', ['count' => '2,048'], 'en'), $page,
        'the English byte unit is still on the German page');

    // The separator on its own, which is what neither half was checking. A
    // German reader parses `2,048` as two-point-zero-four-eight.
    $this->assertStringNotContainsString('2,048', $page,
        'an en-US grouped number is on the German page');
});

test('a region that declares English is English all the way down', function (): void {
    // `diffForHumans()` follows whatever locale the request scoped, so once
    // this route was extracted an unextracted class started building 'Reported
    // vor 2 Minuten' -- an English word glued to a German duration, rendered
    // inside a panel that declares itself English and therefore announced
    // entirely as English.
    //
    // An exception has to hold all the way down or it is not an exception.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $freshness = app(CobrowseSnapshotFreshness::class);

    // Rendered under a German request, as the extracted route now is.
    $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk();

    $formatted = $freshness->format(now()->subMinutes(2)->toJSON());

    expect($formatted['reported_label'])->toBeString();

    // NOT expect()->not->toContain($needle, $message): toContain is variadic,
    // so the message becomes a second needle and the assertion always passes.
    // Third time in this file. The habit that works is to reach for
    // assertStringNotContainsString whenever a message is wanted.
    $this->assertStringNotContainsString('vor ', $formatted['reported_label'],
        'the English freshness label carries a German duration');
});

test('this file never passes a message to a variadic matcher', function (): void {
    // Three times in this file I have written
    //
    //     expect($x)->not->toContain($needle, 'why this matters');
    //
    // `toContain` is variadic: the message becomes a SECOND NEEDLE, and the
    // negated form then asserts that neither appears -- which is trivially true
    // of a sentence nobody renders. The assertion always passes. `toHaveKey`
    // has the same shape with its second argument being the expected VALUE.
    //
    // Each time it hid a real defect that only a mutation caught, and the third
    // was minutes after writing the comment warning about it. Knowing the trap
    // is clearly not enough, so it is mechanical now.
    //
    // Scoped to this file because this is where it keeps happening; a
    // suite-wide version would be a policy decision rather than a fix.
    // Comments stripped first. This is the SECOND guard in this file to match
    // its own documentation -- the `<option>` one did it too -- because a
    // source guard's explanation necessarily contains the thing it looks for.
    // Any guard that reads code has to exclude the prose about that code.
    $source = preg_replace('#//[^\n]*#', '', file_get_contents(__FILE__)) ?? '';

    preg_match_all("/->(toContain|toHaveKey)\(\s*([^,()]+),\s*'([^']{12,})'\s*\)/", $source, $found, PREG_SET_ORDER);

    $offenders = [];

    foreach ($found as $match) {
        // A message has spaces and reads like a sentence; a second needle
        // rarely does both.
        if (str_contains($match[3], ' ') && preg_match('/\b(the|a|is|not|so|and|for|that|this|no|its)\b/', $match[3]) === 1) {
            $offenders[] = '->'.$match[1]."(..., '".$match[3]."')";
        }
    }

    expect($offenders)->toBe([], 'a message passed to a variadic matcher, where it becomes a second needle and the assertion always passes');
});

test('the snapshot age is German on the first paint, not only after an update', function (): void {
    // CobrowseSnapshotFreshness pins English deliberately -- a broadcast builds
    // it too, from a queue worker with no reader whose language it could
    // follow. The realtime handler never reads that value; it formats the raw
    // timestamp client-side.
    //
    // The server's FIRST paint is the one thing that handler has not
    // overwritten, and it interpolated the pinned English duration into a
    // German sentence: "Gemeldet 2 minutes ago".
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;
    $metadata['snapshot']['reported_at'] = now()->subMinutes(2)->toJSON();
    $session->forceFill(['metadata' => $metadata])->save();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

    $reported = (new DOMXPath($document))
        ->query('//*[@data-cobrowse-snapshot-freshness-reported]')
        ->item(0);

    expect($reported)->not->toBeNull('the freshness line did not render, so this proves nothing');

    $text = trim($reported->textContent);

    // German all the way through: the sentence AND the duration inside it.
    $this->assertStringContainsString('vor ', $text,
        "the snapshot age is still English on the first paint: {$text}");
    $this->assertStringNotContainsString('ago', $text,
        "an English duration is still on the German panel: {$text}");
});

test('no unreplaced placeholder ever reaches the page', function (): void {
    // A sentence rendered without its parameters shows `:elapsed` or `:count`
    // to the agent. It looks like copy, it is in the right language, and it is
    // nonsense -- so no comparison or key check can see it.
    //
    // This exists because a mutation survived them both: pinning a timing value
    // to null rendered "Wartet seit :elapsed auf Antwort", which still contains
    // every German word the assertions were looking for.
    //
    // The placeholder names come from the catalogues, so the guard cannot go
    // stale as sentences gain parameters.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    conversationQueueLanguageTicketStates($world, $conversation);
    conversationQueueLanguageIntegrationStates($world);

    $placeholders = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
        $walk = function (array $values) use (&$walk, &$placeholders): void {
            foreach ($values as $value) {
                if (is_array($value)) {
                    $walk($value);

                    continue;
                }

                if (is_string($value) && preg_match_all('/:([a-z][a-z_]{2,})/', $value, $found) > 0) {
                    $placeholders = array_merge($placeholders, $found[1]);
                }
            }
        };

        $walk(require $file);
    }

    $placeholders = array_values(array_unique($placeholders));

    expect($placeholders)->not->toBe([]);

    $states = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.tickets.index'),
        route('dashboard.conversations.show', $conversation->support_code),
        route('dashboard.account.break-glass.index'),
        route('dashboard.account.integrations'),
        route('dashboard.account.show'),
        route('operator.settings.localization.edit'),
        route('operator.settings.scanning.edit'),
        route('operator.settings.mail.edit'),
        route('operator.settings.storage.edit'),
        route('operator.settings.backups.edit'),
        route('operator.settings.backups.history'),
        route('operator.settings.backups.restore'),
        route('operator.dashboard'),
        route('operator.onboarding'),
    ];

    foreach (['de', 'en'] as $locale) {
        foreach ($states as $url) {
            $text = conversationQueueLanguageVisibleText(
                (string) $this->actingAs(conversationQueueLanguageReaderForUrl($world, $url, $locale))
                    ->get($url)
                    ->assertOk()
                    ->getContent()
            );

            foreach ($placeholders as $placeholder) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<![\pL\pN]):'.preg_quote($placeholder, '/').'\b/u',
                    $text,
                    "unreplaced :{$placeholder} rendered at {$url} in {$locale}"
                );
            }
        }
    }
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
    conversationQueueLanguageIntegrationStates($world);

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
        route('dashboard.conversations.show', $conversation->support_code),
        route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'tab' => 'cobrowse']),
        route('dashboard.account.audit.index'),
        route('dashboard.account.break-glass.index'),
        route('dashboard.account.integrations'),
        route('dashboard.account.show'),
        route('operator.settings.localization.edit'),
        route('operator.settings.scanning.edit'),
        route('operator.settings.mail.edit'),
        route('operator.settings.storage.edit'),
        route('operator.settings.backups.edit'),
        route('operator.settings.backups.history'),
        route('operator.settings.backups.restore'),
        route('operator.dashboard'),
        route('operator.onboarding'),
        route('dashboard.account.audit.index', ['audit_search' => 'zzzz']),
    ];

    $catalogues = collect(glob(lang_path('en/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->push('validation')
        ->unique()
        ->values();

    foreach (['de', 'en'] as $locale) {
        foreach ($states as $url) {
            $html = (string) $this->actingAs(conversationQueueLanguageReaderForUrl($world, $url, $locale))
                ->get($url)
                ->assertOk()
                ->getContent();
            $text = collect(conversationQueueLanguageAnnouncements($html))
                ->filter(fn (array $announcement): bool => $announcement['language'] === $locale)
                ->pluck('text')
                ->implode(' ');

            // A KEY, not merely a catalogue name followed by a dot: an English
            // sentence ending "...for your profile." contains `profile.` and is
            // perfectly good copy. A key is the catalogue, a dot, and a
            // lowercase section -- no space between them.
            // Every catalogue, not the ones that existed when this was written:
            // two mutations survived because `tickets` was missing from here,
            // so a raw `tickets.row.…` key rendered unnoticed.
            foreach ($catalogues as $catalogue) {
                // Ignore catalogue-shaped hostnames in example URLs (for
                // example `support.example.com`). A raw key is visible copy,
                // so it cannot begin immediately after a URL slash or `@`.
                $pattern = '/(?<![\/@])\b'.preg_quote($catalogue, '/').'\.[a-z][a-z_]*(\.[a-zA-Z_]+)*/';

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

test('the detail page counts name what they are counting', function (): void {
    // No comparison guard can see this one: swapping one count for another
    // still renders German, just the wrong German -- "0 Felder" where the page
    // means "0 früher". Same class as the ticket queue's status heading.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $german = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent()
    );

    // Anchored to the section each count belongs to. A page-wide
    // `assertStringContainsString` passes while one of three call sites is
    // wrong, because the other two still render the word -- which is exactly
    // what a mutation of one of them proved.
    $near = function (string $text, string $heading, string $expected): void {
        $at = mb_strpos($text, $heading);

        $this->assertNotFalse($at, "section heading missing: {$heading}");
        $this->assertStringContainsString($expected, mb_substr($text, $at, 160), "count under: {$heading}");
    };

    $near($german, 'Host-Kontext', 'Feld');
    $near($german, 'Verlauf auf dieser Website', 'früher');

    $inEnglish = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['en'])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent()
    );

    $near($inEnglish, 'Host context', 'field');
    $near($inEnglish, 'History on this site', 'previous');
});

test('a reply helper translates its name but never its message', function (): void {
    // The sharpest boundary on this page, and it is a PRODUCT boundary rather
    // than a translation one.
    //
    // A helper's LABEL is dashboard chrome: it names the helper to the agent
    // choosing it. Its BODY is a message to the VISITOR -- the composer drops
    // it into the reply box and the agent sends it. Translating the body would
    // couple what a visitor receives to the language their agent happens to
    // read the dashboard in, so a German-speaking agent would send German to an
    // English visitor without ever choosing to.
    App::setLocale('de');
    $german = AgentReplyTemplate::options();

    App::setLocale('en');
    $english = AgentReplyTemplate::options();

    foreach (array_keys($english) as $key) {
        // The name changes with the dashboard...
        expect($german[$key]['label'])->not->toBe($english[$key]['label'])
            // ...and the message the visitor would receive does not.
            ->and($german[$key]['body'])->toBe($english[$key]['body']);
    }
});

test('every detail-page action confirms itself in the agent language', function (): void {
    // The flash is written in one request and read in the next, so the KEY
    // travels and the page translates it -- the same rule the profile page
    // follows. Asserting the session holds a key proves nothing about what the
    // agent sees, so this follows the redirect.
    $world = conversationQueueLanguageWorld(conversations: 1);
    $conversation = Conversation::query()->firstOrFail();
    $agent = $world['agents']['de'];

    $this->actingAs($agent)
        ->from(route('dashboard.conversations.show', $conversation->support_code))
        ->followingRedirects()
        ->post(route('dashboard.conversations.close', $conversation->support_code))
        ->assertOk()
        ->assertSee('Unterhaltung geschlossen.')
        ->assertDontSee('Conversation closed.')
        // A raw key reaching the page is the specific failure of flashing one.
        ->assertDontSee('conversations.flash');

    $this->actingAs($world['agents']['en'])
        ->from(route('dashboard.conversations.show', $conversation->support_code))
        ->followingRedirects()
        ->post(route('dashboard.conversations.reopen', $conversation->support_code))
        ->assertOk()
        ->assertSee('Conversation reopened.')
        ->assertDontSee('Unterhaltung wieder geöffnet.');
});

test('the linked ticket panel is translated, values and all', function (): void {
    // The comparison guard cannot judge these: the timing values carry an
    // elapsed time (digits) and the preview body carries the message itself
    // (data), so both segments are discarded before they are compared. Same
    // blind spots as the queue rows, same answer.
    $world = conversationQueueLanguageWorld(conversations: 1);
    $conversation = Conversation::query()->firstOrFail();

    // A ticket with no messages and no description, so the preview falls back
    // to copy rather than quoting a visitor.
    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($conversation->visitor, 'requester')
        ->create(['category' => 'task', 'priority' => 'low', 'status' => 'open', 'subject' => 'Datenpunkt linked', 'description' => null]);

    $german = conversationQueueLanguageVisibleText(
        (string) $this->actingAs($world['agents']['de'])
            ->get(route('dashboard.conversations.show', $conversation->support_code))
            ->assertOk()
            ->getContent()
    );

    foreach (['Geöffnet', 'Wartet', 'Noch keine Aktivitätsvorschau', 'Öffnen Sie das Ticket'] as $expected) {
        $this->assertStringContainsString($expected, $german, "linked ticket panel: {$expected}");
    }

    foreach (['Opened ', 'Waiting on', 'No activity preview yet', 'Open the ticket to add context'] as $english) {
        $this->assertStringNotContainsString($english, $german, "English left in the linked ticket panel: {$english}");
    }
});

test('a German cobrowse sentence is never marked English because its value is', function (): void {
    // The leak guard reads the effective `lang` and skips anything declaring
    // itself English, which is correct for a recorded exception and blind to a
    // MISMARKED region. So this asserts the thing the guard cannot: a sentence
    // that comes from the German catalogue must not sit inside `lang="en"`.
    //
    // The bug: `x-lang` wrapped the whole `Läuft ab :elapsed` sentence and took
    // its language from the VALUE, so an unparseable timestamp -- which makes
    // `expiresAt()` null and the formatter emit the English `Expiry
    // unavailable` -- marked the German sentence as English too. A wrong marker
    // is not a smaller mistake than a missing one.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;
    $metadata['resync_request'] = [
        'id' => 'resync-unparseable-fixture',
        'requested_by_name' => 'Support',
        'requested_at' => 'not-a-timestamp',
        'fulfilled_at' => null,
    ];

    $session->forceFill(['metadata' => $metadata])->save();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    // Every German catalogue sentence for this panel, wherever it appears, must
    // not be inside a region announcing itself as English.
    foreach (['cobrowse.labels.expires', 'cobrowse.labels.received', 'cobrowse.labels.expired'] as $key) {
        $sentence = trim(str_replace(':elapsed', '', __($key, [], 'de')));

        if ($sentence === '' || ! str_contains($html, $sentence)) {
            continue;
        }

        $before = substr($html, 0, strpos($html, $sentence));
        $openedEnglish = strrpos($before, '<span lang="en">');
        $closedSince = $openedEnglish === false ? false : strpos($before, '</span>', $openedEnglish);

        expect($openedEnglish === false || $closedSince !== false)
            ->toBeTrue("German sentence for {$key} is inside an English-marked region");
    }
});

test('a four-digit count is grouped the way the reading agent groups numbers', function (): void {
    // The revert-detector for the number seam. `number_format()` writes
    // `4,213` in every language the dashboard speaks, and this page is
    // extracted, so a German agent was reading a four-thousand count as
    // four-point-two-one-three. Plausible at both readings, which is why it
    // shipped.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();
    $conversation = $session->conversation;

    $metadata = $session->metadata;
    $metadata['snapshot']['node_count'] = 4213;
    // The telemetry counts are rendered on their own rather than inside a
    // sentence, and were formatted inside the model -- so a whole-file
    // exemption in the coverage guard hid them.
    $metadata['telemetry']['dropped_batches'] = 5314;
    $metadata['telemetry']['reconnects'] = 6415;
    $metadata['telemetry']['samples'] = 7516;
    $session->forceFill(['metadata' => $metadata])->save();

    $german = conversationQueueLanguageVisibleText((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent());

    expect($german)->toContain('4.213')
        ->and($german)->not->toContain('4,213')
        ->and($german)->toContain('5.314')
        ->and($german)->toContain('6.415')
        ->and($german)->toContain('7.516')
        // `reconnects` can be asserted negatively now: the transport-health
        // block used to render it bare under a translated label with an en-US
        // separator, so the page carried `6,415` and `6.415` at once. The
        // model hands out the raw value and the surface formats it.
        //
        // `dropped_batches` still cannot: it also appears in the pressure
        // sentence, which really is English inside a model. `5,314` is
        // legitimately on this page welded to an English noun, and asserting
        // against it here would be asserting that the extraction slice's work
        // was done.
        ->and($german)->not->toContain('6,415')
        ->and($german)->not->toContain('7,516');

    // Both halves matter. The negative one is what catches a partial revert
    // that leaves the seam in place and bypasses it at one call site.
    $english = conversationQueueLanguageVisibleText((string) $this->actingAs($world['agents']['en'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent());

    expect($english)->toContain('4,213')
        ->and($english)->not->toContain('4.213')
        ->and($english)->toContain('7,516')
        ->and($english)->not->toContain('7.516');
});

test('the live-update path is given the agent language, not the browser', function (): void {
    // The server half of this is undone within seconds without the client
    // half: `applyPreviewState()` rewrites the same nodes the server just
    // painted, and `toLocaleString()` with no argument follows the browser.
    // The live block only renders when broadcasting is configured, which is
    // the only condition under which any of this runs at all.
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'test-key');
    config()->set('broadcasting.connections.reverb.options.host', 'localhost');
    config()->set('broadcasting.connections.reverb.options.port', 8080);

    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();

    $html = (string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $session->conversation->support_code))
        ->assertOk()
        ->getContent();

    // The block is present at all -- without this the rest asserts nothing.
    expect($html)->toContain('var realtimeLabels =');

    // The agent's language reaches the script.
    expect($html)->toContain('"locale":"de"');

    // ...and every live formatter goes through the helper that uses it.
    expect(substr_count($html, 'toLocaleString()'))->toBe(0)
        ->and($html)->toContain('function readerNumber(');
});

test('the queue-pressure count is grouped for the agent, on both renders', function (): void {
    // This sentence is fully translated -- `:count verworfene Stapel` -- and
    // renders on two extracted routes, so the only English thing left in it
    // was the number. It was exempted from the number guard as "English
    // awaiting extraction", which it is not, and the exemption hid it.
    //
    // The live handler already formatted these for the agent, so the two
    // halves disagreed: the server painted `1,000` and the first websocket
    // message rewrote the same node as `1.000`.
    $world = conversationQueueLanguageWorld();
    $session = conversationQueueLanguageCobrowseSession();

    $metadata = $session->metadata;
    $metadata['telemetry']['dropped_batches'] = 1000;
    $metadata['telemetry']['reported_at'] = now()->toIso8601String();
    $session->forceFill(['metadata' => $metadata])->save();

    $german = conversationQueueLanguageVisibleText((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $session->conversation->support_code))
        ->assertOk()
        ->getContent());

    expect($german)->toContain('1.000 verworfene Stapel')
        ->and($german)->not->toContain('1,000 verworfene Stapel');
});

test('an article is announced as the account\'s words, not the agent\'s language', function (): void {
    // An article is written for VISITORS, so its language is whatever the
    // account writes in -- which is not the language this admin happens to read
    // the dashboard in. The extracted page declares the agent's language for
    // the whole document, so without a reset a screen reader pronounces English
    // article prose with German phonetics.
    //
    // The render audit cannot see this: the article's text is marked as DATA
    // there, so it is excused from the translation check and nothing looks at
    // how it is announced.
    //
    // Asserted per ELEMENT, not by asking whether the article's words appear
    // inside SOME `lang=""` node anywhere on the page. The first version did
    // that, and the title alone is rendered in three marked places -- so
    // deleting any one of them left another to answer for it and five of six
    // deletions passed. The strings collide by design; the elements do not.
    $world = conversationQueueLanguageWorld();
    $article = $world['article'];

    $xpathFor = function (string $html): DOMXPath {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($document);
    };

    $index = $xpathFor((string) $this->actingAs($world['admins']['de'])
        ->get(route('dashboard.account.articles.index'))->assertOk()->getContent());

    // The CREATION controls as well as the list. Codex found these on the
    // second pass: the detail page's editor was reset and the create form one
    // section above it was not, so the same words were announced differently
    // depending on which form the agent was in.
    foreach ([
        'the new-article title field' => '//input[@id="article_title"]',
        'the new-article body field' => '//textarea[@id="article_body"]',
        // The query is a search for the account's own words. Its LABEL stays
        // in the agent's language and is checked below.
        'the search field' => '//input[@id="article_search"]',
    ] as $label => $query) {
        $control = $index->query($query)->item(0);

        expect($control)->not->toBeNull("{$label} did not render; this guard is checking nothing")
            ->and($control)->toBeInstanceOf(DOMElement::class);

        expect($control->hasAttribute('lang'))
            ->toBeTrue("{$label} carries no lang reset, so what the agent writes is announced in the dashboard language");

        expect($control->getAttribute('lang'))->toBe('');
    }

    $link = $index->query('//a[contains(@href, "'.$article->slug.'") or contains(@href, "/articles/'.$article->id.'")]')->item(0);

    expect($link)->not->toBeNull('the article did not render in the list')
        ->and($link)->toBeInstanceOf(DOMElement::class);

    // `hasAttribute` FIRST. `getAttribute('lang')` returns the empty string
    // both for `lang=""` and for no `lang` at all, so a value check alone
    // passes on exactly the markup this guard exists to reject.
    expect($link->hasAttribute('lang'))->toBeTrue('the list title carries no lang reset')
        ->and($link->getAttribute('lang'))->toBe('', 'the list title is announced in the agent language');

    // The search term echoed back in the empty state. The sentence around it
    // is ours and stays German; the term is whatever the agent typed, which is
    // the account's language. Interpolated data is excused from the render
    // audit's translation check, so only this can see it.
    $searched = $xpathFor((string) $this->actingAs($world['admins']['de'])
        ->get(route('dashboard.account.articles.index', ['article_search' => 'Kundenrueckerstattung zzz']))
        ->assertOk()->getContent());

    $echoed = $searched->query('//*[@lang=""][contains(text(), "Kundenrueckerstattung zzz")]')->item(0);

    expect($echoed)->not->toBeNull('the search term is echoed in the agent language rather than as the words the agent typed');

    // The search field's own label is ours and is not reset with it, or the
    // agent would be told what the field is for in an undeclared language.
    $searchLabel = $index->query('//label[@for="article_search"]')->item(0);

    expect($searchLabel)->not->toBeNull('the search label did not render')
        ->and($searchLabel->hasAttribute('lang'))->toBeFalse('the search LABEL was reset along with its field');

    $detail = $xpathFor((string) $this->actingAs($world['admins']['de'])
        ->get(route('dashboard.account.articles.show', $article))->assertOk()->getContent());

    // The document title, which is what a tab and every navigation
    // announcement read out. `<title>` takes `lang` like any other element.
    $documentTitle = $detail->query('//title')->item(0);

    expect($documentTitle)->not->toBeNull('the document has no title')
        ->and(trim($documentTitle->textContent))->toBe($article->title)
        ->and($documentTitle->hasAttribute('lang'))
        ->toBeTrue('the document title is the article\'s words announced in the agent language');

    expect($documentTitle->getAttribute('lang'))->toBe('');

    // Each region that holds the article, named separately so a deletion in
    // one cannot be covered by another.
    $regions = [
        'the page heading' => '//h1',
        'the slug' => '//code[normalize-space(text())="'.$article->slug.'"]',
        'the title field' => '//input[@id="article_title"]',
        'the body field' => '//textarea[@id="article_body"]',
        'the preview' => '//*[contains(@class, "article-preview")]',
    ];

    foreach ($regions as $label => $query) {
        $node = $detail->query($query)->item(0);

        expect($node)->not->toBeNull("{$label} did not render; this guard is checking nothing")
            ->and($node)->toBeInstanceOf(DOMElement::class);

        expect($node->hasAttribute('lang'))
            ->toBeTrue("{$label} carries no lang reset, so it is announced in the agent language");

        expect($node->getAttribute('lang'))
            ->toBe('', "{$label} declares a language rather than the account's unknown one");
    }

    // And the reset stopped at the article: the page's own copy is still
    // announced in the agent's language, or this would be marking the whole
    // document unknown and calling it a fix.
    $heading = $detail->query('//h2[@id="article-preview-heading"]')->item(0);

    expect($heading)->not->toBeNull('the preview heading did not render')
        ->and($heading->hasAttribute('lang'))
        ->toBeFalse('the page\'s own copy was reset along with the article, which marks the document unknown and calls it a fix');
});

test('an API token is announced as the account\'s words, not the agent\'s language', function (): void {
    // Same class as the articles page one commit below: a token's name, the
    // sites it reaches, the agent who issued it and the credential hint are the
    // account's own data, and the extracted page declares the agent's locale
    // for the whole document.
    //
    // Per ELEMENT and via `hasAttribute` first, for the two reasons the
    // articles guard records: the same string renders in several marked places,
    // and `getAttribute('lang')` cannot tell `lang=""` from no `lang` at all.
    $world = conversationQueueLanguageWorld();

    $html = (string) $this->actingAs($world['admins']['de'])
        ->get(route('dashboard.account.api-tokens.index'))->assertOk()->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $regions = [
        // The CREATE form as well as the table. The articles slice shipped with
        // its editor reset and its create form not, and the same split was
        // sitting here.
        'the new-token name field' => '//input[@id="api_token_name"]',
        'the token name' => '//strong[normalize-space(text())="Acme Datenpunkt Sync"]',
        'the issuing agent' => '//span[normalize-space(text())="Ausgeber Datenpunkt"]',
        'the credential hint' => '//code[starts-with(normalize-space(text()), "'.ApiToken::PREFIX.'")]',
        'a site the token reaches' => '//span[normalize-space(text())="Acme Datenpunkt Docs"]',
    ];

    foreach ($regions as $label => $query) {
        $node = $xpath->query($query)->item(0);

        expect($node)->not->toBeNull("{$label} did not render; this guard is checking nothing")
            ->and($node)->toBeInstanceOf(DOMElement::class);

        expect($node->hasAttribute('lang'))
            ->toBeTrue("{$label} carries no lang reset, so it is announced in the agent language");

        expect($node->getAttribute('lang'))
            ->toBe('', "{$label} declares a language rather than the account's unknown one");
    }

    // And the page's own copy is untouched, or this marks the document unknown
    // and calls it a fix.
    $heading = $xpath->query('//h2[@id="api-token-list-heading"]')->item(0);

    expect($heading)->not->toBeNull('the tokens heading did not render')
        ->and($heading->hasAttribute('lang'))->toBeFalse('the page\'s own heading was reset along with the account\'s data');
});

test('the live board\'s script speaks the agent language', function (): void {
    // MOST of this page's copy is in its script, and the render audit cannot
    // see a word of it: `conversationQueueLanguageVisibleText` strips
    // `<script>` before it looks at anything, deliberately, because script
    // bodies are not copy on every other page in the dashboard.
    //
    // So the audit passing on this route proves the table and the notices and
    // nothing about the board's live state -- the reconnect notice, the
    // durations, the empty page cell, the "not in touch yet" row. Those are
    // rendered into one object by Blade, and this reads that object.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.options.host' => 'wayfindr.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $world = conversationQueueLanguageWorld();

    $copyFor = function (User $agent) use ($world): array {
        $html = (string) $this->actingAs($agent)
            ->get(route('dashboard.sites.live', $world['site']))->assertOk()->getContent();

        $this->assertStringContainsString('data-live-board', $html,
            'the board did not render, so its script never ran');

        // BOTH objects the script gets its words from. `copy` is this page's
        // own strings; `labels` is the presence vocabulary, handed over by the
        // controller because one socket message reaches every agent watching
        // and they do not all read the same language.
        //
        // Reading only `copy` left the controller free to go back to the
        // English support class with the whole suite green -- the labels are
        // rendered into the script, and the audit strips scripts.
        $objects = [];

        foreach (['copy', 'labels'] as $name) {
            $matched = preg_match('/var '.$name.' = (\{.*?\});/s', $html, $found);

            expect($matched)->toBe(1, "the script no longer declares `{$name}`; this guard is reading nothing");

            $decoded = json_decode($found[1], true);

            expect($decoded)->toBeArray()->not->toBeEmpty();

            foreach ($decoded as $key => $value) {
                $objects[$name.'.'.$key] = $value;
            }
        }

        return $objects;
    };

    $german = $copyFor($world['admins']['de']);
    $english = $copyFor($world['admins']['en']);

    expect(array_keys($german))->toBe(array_keys($english), 'the two renders declare different copy keys');

    // An em dash is punctuation. Everything else on both objects, including
    // all four presence states, has to differ.
    $identicalIsCorrect = ['copy.unknown_duration'];

    $untranslated = [];

    foreach ($german as $key => $value) {
        if (in_array($key, $identicalIsCorrect, true)) {
            continue;
        }

        if ($value === $english[$key]) {
            $untranslated[] = "{$key} = {$value}";
        }
    }

    expect($untranslated)->toBe([], implode("\n", [
        'These reach the board in English on a German page:',
        ...$untranslated,
        '',
        'The render audit cannot see them -- it strips <script> -- so this is',
        'the only thing that will.',
    ]));

    // And the one that is meant to be identical still is, or the exception
    // above is excusing something that has started to differ.
    expect($german['copy.unknown_duration'])->toBe($english['copy.unknown_duration']);
});

test('the live board announces the account\'s words as its own', function (): void {
    // Three fragments on this page belong to the account and not to the agent:
    // the site's name inside our heading, the visitor's name, and the address
    // of the page they are on. The document declares the agent's language, so
    // each is reset to `lang=""` -- HTML's "unknown".
    //
    // The heading is the interesting one. It MIXES the two, so neither
    // `titleLang` nor leaving it bare is right: `x-page-header` takes a slot so
    // the catalogue keeps the word order and only the site's name is marked.
    $world = conversationQueueLanguageWorld();

    $html = (string) $this->actingAs($world['admins']['de'])
        ->get(route('dashboard.sites.live', $world['site']))->assertOk()->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $regions = [
        'the site name inside the heading' => '//h1/span',
        'the visitor name' => '//span[normalize-space(text())="Acme Datenpunkt Person"]',
        'the page the visitor is on' => '//code[contains(text(), "datenpunkt/preise")]',
    ];

    foreach ($regions as $label => $query) {
        $node = $xpath->query($query)->item(0);

        expect($node)->not->toBeNull("{$label} did not render; this guard is checking nothing")
            ->and($node)->toBeInstanceOf(DOMElement::class);

        expect($node->hasAttribute('lang'))
            ->toBeTrue("{$label} carries no lang reset, so it is announced in the agent language");

        expect($node->getAttribute('lang'))->toBe('');
    }

    // The heading's OWN words are not reset with the fragment inside it, which
    // is the whole reason this is a slot rather than `title-lang=""`.
    $heading = $xpath->query('//h1')->item(0);

    expect($heading)->not->toBeNull()
        ->and($heading->hasAttribute('lang'))->toBeFalse('the whole heading was marked unknown, not just the site name');

    // And it reads as the whole German sentence with the site's name in the
    // place the catalogue puts it -- which is the thing a slot can get wrong by
    // rendering the fragment and dropping our half, or the other way round.
    expect(trim((string) preg_replace('/\s+/u', ' ', $heading->textContent)))
        ->toBe(__('sites_live.heading', ['site' => $world['site']->name], 'de'));
});

test('the live count is grouped for the reader and raw for the script', function (): void {
    // Grouping the count broke the board. The script initialises and resyncs
    // `presentTotal` from the element, and a grouped string is not a number any
    // more: `Number('1.000')` is 1 for a German agent and `Number('1,000')` is
    // NaN for an English one, so the next socket event or fifteen-second
    // refresh collapsed the total toward the rendered rows.
    //
    // FOUR FIGURES, built here rather than assumed. Under a thousand nothing is
    // grouped, so the shared world's three visitors pass this whether or not
    // the split exists -- which is how the defect got past a green suite in the
    // first place.
    $world = conversationQueueLanguageWorld();

    $seen = now()->subMinute();
    $rows = [];

    for ($i = 0; $i < 1200; $i++) {
        $rows[] = [
            'site_id' => $world['site']->id,
            'anonymous_id' => 'anon-crowd-'.$i,
            'metadata' => '[]',
            'last_seen_at' => $seen,
            'last_web_seen_at' => $seen,
            'created_at' => $seen,
            'updated_at' => $seen,
        ];
    }

    // One statement rather than 1200 model saves: the point is the size of the
    // number, not the shape of the rows.
    Visitor::query()->insert($rows);

    $html = (string) $this->actingAs($world['admins']['de'])
        ->get(route('dashboard.sites.live', $world['site']))->assertOk()->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $count = $xpath->query('//*[@data-live-count]')->item(0);

    expect($count)->not->toBeNull('the live count did not render')
        ->and($count)->toBeInstanceOf(DOMElement::class);

    $raw = $count->getAttribute('data-live-total');
    $shown = trim($count->textContent);

    expect($count->hasAttribute('data-live-total'))
        ->toBeTrue('the script has nothing to read but the reader-facing text');

    expect($raw)->toMatch('/^\d+$/', 'the machine-readable total is grouped too, so the script still cannot parse it');
    expect((int) $raw)->toBeGreaterThan(999, 'the fixture did not reach the size where grouping happens');

    // The reader's number IS grouped, or this test is passing on a page that
    // never had the problem.
    expect($shown)->not->toBe($raw, 'the count was not grouped for the reader, so this proves nothing');

    // And the German grouping is exactly what JavaScript would misread as 1.
    expect($shown)->toContain('.');
});

test('nothing in the live board parses a number out of rendered text', function (): void {
    // The count is grouped for the reader, so the rendered text is not a number
    // any more. Two places read it back and I fixed one: start-up took the new
    // attribute while `resyncBoard()` kept parsing the fetched snapshot's TEXT,
    // so the corruption moved from page load to every resync.
    //
    // Source-level and pattern-based, because that is the shape of the mistake:
    // I swept for the variable I knew (`countEl`) rather than for what the code
    // was doing, and the second site used a different name.
    $source = (string) file_get_contents(
        resource_path('views/agent/sites/live.blade.php')
    );

    // Comments describe the trap; they are not the trap.
    $code = (string) preg_replace('#//[^\n]*#', '', $source);

    $matched = preg_match_all('/(?:Number|parseInt|parseFloat)\s*\(\s*[A-Za-z_$][\w$.]*\.textContent/', $code, $found);

    expect($matched)->toBe(0, implode("\n", [
        'A number is being parsed out of text that is grouped for the reader:',
        ...($found[0] ?? []),
        '',
        'Read `data-live-total` instead. `Number("1.000")` is 1 and',
        '`Number("1,000")` is NaN.',
    ]));

    // And the guard can still see the shape it is looking for, or a rename
    // would quietly retire it.
    expect(preg_match('/(?:Number|parseInt|parseFloat)\s*\(\s*[A-Za-z_$][\w$.]*\.textContent/', 'x = Number(freshCount.textContent) || 0;'))
        ->toBe(1, 'the pattern no longer recognises the call it was written for');
});

test('the conversation surfaces announce the visitor\'s words as the visitor\'s', function (): void {
    // The queue and the detail page were extracted before the `lang=""` rule
    // was being applied consistently, so three visitor-derived values on the
    // busiest pages in the product still inherited the agent's language: the
    // queue's visitor label, and the detail page's visitor name and two page
    // addresses.
    //
    // Each has a translated FALLBACK, which is why the reset follows the branch
    // rather than sitting on the element. Marking unconditionally would
    // announce our own sentence as unknown, which is the same defect pointing
    // the other way.
    $world = conversationQueueLanguageWorld();
    $conversation = Conversation::query()->firstOrFail();

    $conversation->visitor->update([
        'name' => 'Acme Datenpunkt Besuch',
        'metadata' => ['last_page_url' => 'https://acme.example/datenpunkt/kasse'],
    ]);

    // The ENTRY page is the conversation's, not the visitor's: one visitor can
    // start several conversations from different pages.
    $conversation->update([
        'metadata' => ['started_page_url' => 'https://acme.example/datenpunkt/start'],
    ]);

    $xpathFor = function (string $html): DOMXPath {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($document);
    };

    $queue = $xpathFor((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.index'))->assertOk()->getContent());

    $label = $queue->query('//span[contains(@class, "wf-queue-assignee")][contains(text(), "Acme Datenpunkt")]')->item(0);

    expect($label)->not->toBeNull('the visitor label did not render; this guard is checking nothing')
        ->and($label->hasAttribute('lang'))->toBeTrue('the queue announces the visitor name in the agent language');

    $detail = $xpathFor((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))->assertOk()->getContent());

    // Found by its own LABEL, not by its text. `Nicht gemeldet` is the German
    // for two different keys on this page -- `conversations.detail.context`
    // and `cobrowse.units` -- so a text match returns whichever comes first in
    // the document, and the negative assertion below passed against a node
    // this test never meant to look at.
    // EVERY row under that label, not the first. This page carries two rows
    // labelled `Besucher` -- one showing the anonymous identifier and one the
    // name -- and taking `item(0)` meant the guard checked the marked one and
    // reported clean while the other was announced in German.
    $metaValues = function (string $labelKey) use ($detail): array {
        $label = __($labelKey, [], 'de');

        $nodes = [];

        foreach ($detail->query(
            '//div[contains(@class, "meta-item")][span[contains(@class, "meta-label")][normalize-space(text())="'.$label.'"]]'
            .'/span[contains(@class, "meta-value")]'
        ) as $node) {
            $nodes[] = $node;
        }

        return $nodes;
    };

    foreach ([
        'the visitor' => 'conversations.detail.context.visitor',
        'the latest page' => 'conversations.detail.context.latest_page',
        'the entry page' => 'conversations.detail.context.entry_page',
        'the visitor reference' => 'conversations.detail.references.visitor_reference',
    ] as $what => $labelKey) {
        $nodes = $metaValues($labelKey);

        expect($nodes)->not->toBeEmpty("{$what} did not render; this guard is checking nothing");

        foreach ($nodes as $node) {
            expect($node->hasAttribute('lang'))
                ->toBeTrue("the detail page announces {$what} (\"{$node->textContent}\") in the agent language");

            expect($node->getAttribute('lang'))->toBe('');
        }
    }

    // A visitor who gave NOTHING -- an inbound email whose `From` header has no
    // display name leaves both `name` and `anonymous_id` null, and
    // `visitorContext()` substitutes a translated sentence. That sentence is
    // ours, so the two spans that would otherwise show the visitor's own
    // identifier must not be reset.
    //
    // Nothing else in this suite builds that visitor, which is why the first
    // version of this fix marked both spans unconditionally and was wrong for
    // every email-originated conversation.
    $conversation->visitor->forceFill(['name' => null, 'anonymous_id' => null])->save();

    $anonymous = $xpathFor((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))->assertOk()->getContent());

    $unknown = __('conversations.detail.unknown_visitor', [], 'de');

    foreach ($anonymous->query('//span[contains(@class, "meta-value")]') as $node) {
        if (trim($node->textContent) !== $unknown) {
            continue;
        }

        expect($node->hasAttribute('lang'))
            ->toBeFalse('the unknown-visitor sentence is ours and is announced as an unknown language');
    }

    expect($anonymous->query('//span[contains(@class, "meta-value")][normalize-space(text())="'.$unknown.'"]')->length)
        ->toBeGreaterThan(0, 'the unknown-visitor fallback did not render; this half of the guard checked nothing');

    $conversation->visitor->forceFill(['name' => 'Acme Datenpunkt Besuch', 'anonymous_id' => 'anon-datenpunkt'])->save();

    // And with nothing reported, the FALLBACK is ours and is not reset -- the
    // half of this that marking the element unconditionally would get wrong.
    $conversation->visitor->update(['metadata' => []]);
    $conversation->update(['metadata' => []]);

    $bare = $xpathFor((string) $this->actingAs($world['agents']['de'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))->assertOk()->getContent());

    foreach (['conversations.detail.context.latest_page', 'conversations.detail.context.entry_page'] as $labelKey) {
        $label = __($labelKey, [], 'de');

        $nodes = [];

        foreach ($bare->query(
            '//div[contains(@class, "meta-item")][span[contains(@class, "meta-label")][normalize-space(text())="'.$label.'"]]'
            .'/span[contains(@class, "meta-value")]'
        ) as $node) {
            $nodes[] = $node;
        }

        expect($nodes)->not->toBeEmpty("the {$label} row did not render");

        foreach ($nodes as $node) {
            expect(trim($node->textContent))->toBe(__('conversations.detail.context.not_reported', [], 'de'),
                'the row is not showing its fallback, so this proves nothing');

            expect($node->hasAttribute('lang'))
                ->toBeFalse('our own fallback sentence is announced as an unknown language');
        }
    }
});
