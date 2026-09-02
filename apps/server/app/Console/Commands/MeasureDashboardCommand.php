<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Conversations\ConversationQueueQuery;
use App\Support\ReaderNumber;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use ReflectionProperty;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * How long the dashboard's heaviest pages take, against whatever is in the
 * database.
 *
 * Written for #796, which asks the question a `1.0` has to be able to answer:
 * how much can it take? Pair it with `wayfindr:seed-desk`, which writes a
 * desk-sized account to point this at.
 *
 * **It reports three numbers per page and all three matter.** Milliseconds is
 * what an agent feels. Query count is where an N+1 shows up -- a page whose
 * queries grow with its rows will be fine on the machine you test on and
 * unusable on a busy one. Response size is the one that is easy to forget and
 * was the largest finding the first time this ran: a page can be quick to build
 * and still be a hundred megabytes of HTML that a browser has to parse.
 *
 * Requests are dispatched through the HTTP kernel rather than over a socket, so
 * these figures exclude the network, the web server and TLS. That makes them a
 * floor: a real request is this plus everything in front of it. Comparing runs
 * on one machine is what this is for; comparing machines needs the hardware
 * written down beside the numbers, which
 * `docs/self-hosting/performance-baseline.md` does.
 */
final class MeasureDashboardCommand extends Command
{
    /**
     * Session ids this command's own requests created, purged at the end.
     *
     * @var list<string>
     */
    private array $sessionIds = [];

    protected $signature = 'wayfindr:measure-dashboard
        {--email= : The agent to measure as; defaults to the seeded desk owner}
        {--runs=3 : Measured runs per page, after one warm-up}
        {--page=* : Measure only pages whose name contains this, e.g. --page=ticket}
        {--json : Emit machine-readable rows instead of a table}';

    protected $description = 'Time the dashboard\'s heaviest pages against the data currently in the database.';

    /**
     * The cache this command measures against.
     *
     * Its own store rather than the shared `array` one, so clearing it between
     * requests cannot reach a caller whose default happens to be `array` too --
     * and uniquely named per invocation, because a caller who already defined
     * and RESOLVED a store by a fixed name would have theirs flushed instead:
     * changing the config does not evict what the cache manager has memoised.
     */
    private string $cacheStore = 'wayfindr-measure';

    public function handle(Kernel $kernel): int
    {
        $agent = $this->agent();

        if ($agent === null) {
            $this->components->error('No agent to measure as. Run `wayfindr:seed-desk` first, or pass --email.');

            return self::FAILURE;
        }

        // Whoever was signed in stays signed in. In a long-lived process --
        // `Artisan::call()`, Tinker -- logging in here left everything
        // afterwards authenticated as the measured agent.
        $caller = Auth::user();

        // The LOCALE too. Every synthetic request passes through
        // `SetDashboardLocale`, which calls `App::setLocale()` globally -- so
        // measuring a German agent left the rest of a long-lived process
        // translating into German.
        $callerLocale = App::getLocale();

        // And the bound REQUEST. The HTTP kernel binds each synthetic request
        // into the container, so the last dashboard request this command made
        // stayed bound afterwards -- and anything reading `request()` in that
        // process then read a benchmark's request instead of its own.
        $callerRequest = app()->bound('request') ? app('request') : null;

        // And the ROUTER's idea of where it is. Each synthetic dispatch replaces
        // it process-wide, so restoring only the container's `request` left
        // `Route::current()`, `currentRouteName()` and `Route::is()` answering
        // for the last dashboard page this command measured -- and a caller
        // invoked mid-request makes routing decisions against that.
        $callerRoute = Route::getCurrentRoute();
        $callerRouteRequest = Route::getCurrentRequest();

        // And the SESSION. `Auth::setUser()` no longer migrates it, but the
        // synthetic requests still pass through `StartSession`, which starts
        // its own session on the same shared store -- so the caller was left
        // holding a benchmark's session id and none of their own data.
        // The id is captured UNCONDITIONALLY, and the started flag and the
        // attributes are captured beside it rather than deciding whether to
        // look. Both narrower rules lost something real: keying on
        // `isStarted()` threw away data put there without a `start()`, and
        // keying on "started or holds data" threw away the id of a caller who
        // had selected one with `setId()` and not yet used it -- who then got
        // the benchmark's id back instead of their own.
        $callerSessionStarted = Session::isStarted();
        $callerSession = Session::all();
        $callerSessionId = Session::getId();

        // And the CACHE, which the transaction cannot reach. The detail page's
        // cobrowse audit trail claims a throttle key with `Cache::add()`, so a
        // benchmark was taking a claim that belongs to a real agent -- and
        // suppressing the audit entry their next view should have written.
        //
        // Measured against an array store instead. It keeps the run out of the
        // caller's cache entirely, and it makes every measurement start cold
        // and identical rather than inheriting whatever the last one warmed --
        // which is what a baseline wants anyway.
        //
        // The restore is tested; the isolation is not, and the reason is worth
        // stating rather than leaving as a gap. Nothing the seeded fixture
        // renders writes to the cache -- the cobrowse audit trail is the path
        // that does, and the seeder creates no cobrowse sessions -- so there is
        // no observable difference to assert. That is also a caveat on the
        // detail page's own figure, and the baseline says so.
        $callerCache = config('cache.default');

        // A store of this command's OWN, not the shared `array` one. A caller
        // whose default is already `array` had their keys flushed between every
        // synthetic request -- the isolation reaching into exactly what it was
        // added to protect.
        $this->cacheStore = 'wayfindr-measure-'.bin2hex(random_bytes(6));

        config([
            'cache.stores.'.$this->cacheStore => ['driver' => 'array', 'serialize' => false],
            'cache.default' => $this->cacheStore,
        ]);

        // `setUser`, not `login`. Invoked through `Artisan::call()` during an
        // HTTP request, `Auth::login()` writes to and MIGRATES the caller's
        // session -- a benchmark rotating a live session id. Setting the user
        // resolves it for anything reading `Auth::user()` and touches no
        // session at all; the synthetic requests carry their own user through
        // `setUserResolver` regardless.
        Auth::setUser($agent);

        // Inherited state, turned off before anything is timed. Called through
        // `Artisan::call()` or from Tinker, the connection's query log may
        // already be on -- and then every timed run allocates and retains an
        // entry per query, which is exactly the overhead this command separates
        // the counted request to avoid. Restored at the end, because it belongs
        // to whoever turned it on.
        $wasLogging = DB::logging();

        DB::disableQueryLog();

        // ONE transaction round the whole measurement, always rolled back.
        //
        // Measuring is meant to be an observation, and the conversation detail
        // page is not a read: `show()` marks notifications read and marks the
        // conversation read for the viewer. Run with `--email` against a real
        // agent -- exactly what an operator measuring their own install does --
        // a benchmark silently cleared their state.
        //
        // One transaction rather than one per REQUEST. The guarantee is
        // identical -- no write from any measured request survives -- and one
        // is the cheaper shape: nested inside a transaction a test suite
        // already holds, per-request meant a savepoint per request, and
        // PostgreSQL degrades once a transaction accumulates many
        // subtransactions.
        DB::beginTransaction();

        try {
            return $this->measureAll($kernel, $agent);
        } finally {
            DB::rollBack();

            // Outside the transaction above, or the rollback undoes it.
            $this->purgeSessions();

            // The caller's log is the CALLER's, and it is never flushed here.
            // `disableQueryLog()` only changes the flag, so entries survive it
            // -- a caller who turned logging off and kept the log for later had
            // it erased by the branch that used to be here.
            //
            // Their log gains this command's counted requests either way, which
            // is the lesser harm and is what any code running inside a query
            // log does to it. Counting by DIFFERENCE is what makes not
            // flushing possible.
            if ($wasLogging) {
                DB::enableQueryLog();
            }

            config(['cache.default' => $callerCache]);

            // The store goes with it, so nothing of this run is left resolvable
            // in a long-lived process.
            Cache::forgetDriver($this->cacheStore);
            config(['cache.stores.'.$this->cacheStore => null]);

            $caller === null ? Auth::forgetUser() : Auth::setUser($caller);

            App::setLocale($callerLocale);

            $callerRequest === null
                ? app()->forgetInstance('request')
                : app()->instance('request', $callerRequest);

            $this->restoreRouterTo($callerRoute, $callerRouteRequest);

            // STARTED, then filled. Each synthetic request's `StartSession`
            // save leaves the shared store stopped, so putting back the id and
            // attributes without starting it left the caller holding a session
            // that reads as closed -- and anything checking `isStarted()`
            // behaved as if they had none.
            //
            // A caller who never had a session is the same restore with an
            // empty capture: their own id back, no attributes, and the started
            // flag they came in with. There is no public way to clear that flag,
            // so a caller who was unstarted and whose session a synthetic
            // request started keeps a started empty session -- a much smaller
            // residue than a populated one belonging to a measurement.
            Session::setId($callerSessionId);

            if ($callerSessionStarted) {
                Session::start();
            }

            Session::flush();
            Session::replace($callerSession);
        }
    }

    /**
     * Named around `Command::run()`, which is public and belongs to the base
     * class -- redeclaring it private is a fatal before anything runs.
     */
    private function measureAll(Kernel $kernel, User $agent): int
    {

        $targets = $this->onlyRequested($this->targets($agent));

        $this->warnAboutMemoryLimit($agent, $targets);

        if ($targets === []) {
            $this->components->error('Nothing to measure. Run `wayfindr:seed-desk` first, or widen --page.');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $rows = [];

        foreach ($targets as $label => $uri) {
            $rows[] = $this->measure($kernel, $agent, $label, $uri, $runs);
        }

        // A page that did not render is not a fast page. Reporting success here
        // would put a very good number next to a 404 or a 403, which is the one
        // way a performance baseline can be actively misleading rather than
        // merely incomplete.
        $unrendered = array_values(array_filter($rows, fn (array $row): bool => $row['status'] !== 200));

        if ($unrendered !== []) {
            foreach ($unrendered as $row) {
                $this->components->error("{$row['page']} answered {$row['status']} at {$row['uri']}; its timing measures an error page.");
            }

            return self::FAILURE;
        }

        // The count the agent's queues actually render, not every row in the
        // database. A global count beside another account reports fifty
        // thousand next to timings taken over twenty, which is a baseline that
        // says the opposite of the truth.
        $visible = Conversation::query()
            ->whereIn('site_id', Site::query()->visibleToAgent($agent)->select('id'))
            ->count();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'conversations' => $visible,
                'measured_at' => now()->toJSON(),
                'pages' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Conversations visible to this agent</>', ReaderNumber::count($visible));
        $this->newLine();

        $this->table(
            ['Page', 'ms (median)', 'Queries', 'Response'],
            array_map(fn (array $row): array => [
                $row['page'],
                ReaderNumber::count((int) $row['ms']),
                ReaderNumber::count((int) $row['queries']),
                $this->humanBytes($row['bytes']),
            ], $rows),
        );

        return self::SUCCESS;
    }

    /**
     * @return array{page: string, uri: string, ms: float, queries: int, bytes: int, status: int}
     */
    private function measure(Kernel $kernel, User $agent, string $label, string $uri, int $runs): array
    {
        // One warm-up, discarded. The first request through the kernel pays for
        // autoloading, container resolution and the view cache, none of which an
        // agent's second page view pays again -- so counting it measures the
        // process rather than the page.
        //
        // REALISED, not just dispatched. A streamed response has done almost
        // nothing until its callback runs, so warming it without running that
        // callback left its cold start -- autoloading the CSV escaper, for one
        // -- to be paid by the first TIMED run instead. At `--runs=2` that cold
        // figure went straight into the median. Streamed and ordinary targets
        // get the same warm-up this way.
        ob_start();

        try {
            self::realise($this->send($kernel, $agent, $uri));
        } finally {
            ob_end_clean();
        }

        $timings = [];
        $bytes = 0;
        $status = 0;
        $queries = 0;

        // TIMED runs are uninstrumented. Laravel's query log allocates and
        // retains an entry per query, so leaving it on inside the measured
        // interval charges the page for the measuring -- and the overhead grows
        // with query count, which is exactly the axis the ticket queue's N+1
        // sits on. Timing it instrumented would have inflated the one number
        // that is evidence for the finding.
        for ($run = 0; $run < $runs; $run++) {
            // Savepoint and cache cleared BEFORE the clock, rolled back after
            // it, so the isolation is not part of what the page is charged for.
            $this->isolate();

            DB::beginTransaction();

            // The buffer is opened BEFORE the clock, because catching the
            // output is this command's cost. What happens inside it is the
            // page's: a streamed response has done almost nothing until its
            // callback runs, and for the report export that callback is where
            // every row is escaped and written. Timing only the dispatch
            // published a figure for building a response rather than for
            // producing one.
            ob_start();

            try {
                $startedAt = microtime(true);
                $response = $this->dispatch($kernel, $agent, $uri);
                $body = self::realise($response);
                $timings[] = (microtime(true) - $startedAt) * 1000;
            } finally {
                ob_end_clean();
                DB::rollBack();
            }

            $bytes = strlen($body);

            $status = self::worstStatus($status, $response->getStatusCode());
        }

        // Counted separately, once, and not timed. In a `finally`, because an
        // error escaping this request skipped the disable -- and if logging was
        // off when the command started, the outer restore only flushes, leaving
        // it on for everything afterwards in a long-lived process.
        // Counted from the DIFFERENCE, so nothing has to be flushed. Logging is
        // off for the timed runs above, so the only entries this adds are the
        // counted request's -- and an inherited log keeps everything it had.
        $before = count(DB::getQueryLog());

        DB::enableQueryLog();

        try {
            // Its status counts too. Discarding the counted request's response
            // let an error there sit behind an earlier 200 -- the row reporting
            // success while its query figure came from an error page.
            $counted = $this->send($kernel, $agent, $uri);
            $queries = count(DB::getQueryLog()) - $before;
            $status = self::worstStatus($status, $counted->getStatusCode());
        } finally {
            DB::disableQueryLog();
        }

        sort($timings);

        // Median, not mean. One run that hit a garbage collection should not
        // decide the figure a baseline is compared against.
        //
        // Averaged across the two central values on an EVEN run count, which
        // `$timings[intdiv(count, 2)]` alone does not do: it takes the upper
        // middle, so `--runs=2` over 100ms and 500ms reported 500 rather than
        // 300. The growth table in the baseline was measured with `--runs=2`.
        $middle = intdiv(count($timings), 2);

        $median = count($timings) % 2 === 0
            ? ($timings[$middle - 1] + $timings[$middle]) / 2
            : $timings[$middle];

        return [
            'page' => $label,
            'uri' => $uri,
            'ms' => round($median, 1),
            'queries' => $queries,
            'bytes' => $bytes,
            'status' => $status,
        ];
    }

    /**
     * The status a set of runs should be judged by: any failure, not the last.
     *
     * Overwriting per run kept only the final status, so a transient error page
     * early in the set left its very fast timing in the median while the
     * command reported success -- the misleading-baseline case the status check
     * exists to prevent.
     *
     * A pure function because the case cannot be produced through the command:
     * arranging a page that fails once and then succeeds is not something a
     * measurement run can be asked for.
     */
    public static function worstStatus(int $seen, int $latest): int
    {
        if ($seen === 0 || $seen === 200) {
            return $latest;
        }

        return $seen;
    }

    /**
     * One request, isolated from the ones around it.
     *
     * The outer transaction stops writes surviving the COMMAND; this stops them
     * surviving the REQUEST. Without it the warm-up's read-state write is
     * visible to every timed run after it, so the runs measure a conversation
     * that has already been read while the first visit -- the expensive one, and
     * the one an agent actually makes -- is the only one discarded.
     *
     * NOT covered by a test, and the reason is worth stating rather than
     * leaving as a gap somebody assumes is filled. The difference is invisible
     * from outside the command: the query count comes from a request that runs
     * after the timed ones either way, so it reports the same figure whether or
     * not the earlier requests were isolated. Observing it would mean reaching
     * inside the loop, and a test that reaches that far mostly asserts the
     * shape of the code it is testing.
     */
    private function send(Kernel $kernel, User $agent, string $uri): Response
    {
        $this->isolate();

        DB::beginTransaction();

        try {
            return $this->dispatch($kernel, $agent, $uri);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Put the next request back at the start line.
     *
     * The transaction covers the database; the CACHE is in-memory and shared
     * across every request this command makes, so the warm-up's cobrowse
     * throttle key was still there for the timed runs -- and they measured a
     * page that had decided not to write an audit entry. The same failure the
     * per-request transaction fixed, one store over.
     *
     * Called outside the clock, like the savepoint, so isolating is not part of
     * what a page is charged for.
     */
    private function isolate(): void
    {
        Cache::store($this->cacheStore)->flush();
    }

    /**
     * The request alone, with no isolation around it.
     *
     * Separate from `send()` so the TIMED path can open its savepoint before
     * starting the clock and roll back after stopping it. Timing `send()`
     * charged the page for the savepoint and the rollback -- the same mistake
     * as leaving the query log on inside the measured interval, in a smaller
     * amount.
     */
    /**
     * Remove the sessions this command's own requests created.
     *
     * By id, which is exact: the only sessions removed are ones recorded by
     * `dispatch()`. Failing here must not mask the measurement, so a store that
     * cannot destroy is reported and the rest are still attempted.
     */
    /**
     * Say up front when the memory limit will not survive the run.
     *
     * Normal queue rows are capped now, but the conversation queue still
     * hydrates every live cobrowse session to count the ones needing transport
     * attention. A pathological stale-session set can therefore outgrow the
     * shipped 256M limit even though the visible rows themselves are bounded.
     *
     * A WARNING, never a refusal. The estimate below is a straight line fitted
     * to one measured point, which is enough to be useful and not enough to
     * stop somebody measuring their own install.
     */
    /**
     * @param  array<string, string>  $targets
     */
    private function warnAboutMemoryLimit(User $agent, array $targets): void
    {
        // Only the QUEUES render a row each. Measuring `--page=detail` costs
        // one conversation's messages whatever the desk holds, so warning about
        // gigabytes there is advice about a run that is not happening.
        $kinds = self::queueKinds($targets);

        if ($kinds === []) {
            return;
        }

        // Each queue's OWN table, and only the ones selected. Counting
        // conversations for a ticket run missed installs with few conversations
        // and many tickets entirely -- they got no warning and then ran out of
        // memory.
        //
        // Scoped to what this AGENT can see, because an install with one large
        // tenant and one small one was warning the small one about rows its
        // queue will never render.
        $sites = Site::query()->visibleToAgent($agent)->select('id');

        // Both queues are CAPPED, so their rendered-row cost stops growing with
        // the desk. Estimating either from the table would tell an operator to
        // raise the limit to gigabytes for a page that now renders a bounded
        // response, contradicting the command's own measurement.
        //
        // Conversations with a LIVE cobrowse session are hydrated in full on
        // every conversation-queue render, to count how many need attention,
        // and `withActiveCobrowseSession()` has no age cutoff -- a desk that
        // never ends sessions accumulates them. That set is not capped, so
        // clamping the estimate to the row cap alone would let this method
        // promise a warning it does not give: the command would run out of
        // memory in a path the estimate had decided was bounded.
        // Counted only for the kinds actually selected. Extracting the rule
        // into a pure function moved these out of the per-kind `match` and made
        // them unconditional, so `--page=ticket` opened with two unrelated
        // scans of the conversation tables before discarding both -- a
        // benchmark paying for a page it was told not to measure.
        $wantsConversations = in_array('conversations', $kinds, true);
        $wantsTickets = in_array('tickets', $kinds, true);

        $rows = self::estimatedRows(
            $kinds,
            // The precise table sizes no longer affect the normal row cost;
            // pass the cap and avoid scanning a table just to clamp the answer
            // back to this same number.
            $wantsConversations ? ConversationQueueQuery::DISPLAY_LIMIT : 0,
            $wantsConversations ? Conversation::query()
                ->whereIn('site_id', $sites)
                ->where('status', 'open')
                ->withActiveCobrowseSession()
                ->count() : 0,
            $wantsTickets ? Ticket::QUEUE_DISPLAY_LIMIT : 0,
        );

        $warning = self::memoryWarning(
            $rows,
            $this->memoryLimitInBytes(),
            (string) ini_get('memory_limit'),
            (string) $this->getName(),
        );

        if ($warning === null) {
            return;
        }

        // STDERR under `--json`, for the reason `onlyRequested()` does the same:
        // a notice printed above the document makes the advertised
        // machine-readable output unparseable to everything that reads it.
        if ($this->option('json')) {
            $stderr = $this->getOutput()->getOutput();

            if ($stderr instanceof ConsoleOutputInterface) {
                $stderr->getErrorOutput()->writeln('<comment>'.$warning.'</comment>');
            }

            return;
        }

        $this->components->warn($warning);
    }

    /**
     * Which row-per-record queues the selected pages include.
     *
     * By TABLE, because the two kinds cost different things: a ticket queue
     * renders tickets and a conversation queue renders conversations, and an
     * install can have very few of one and a great many of the other.
     *
     * @param  array<string, string>  $targets
     * @return list<string>
     */
    public static function queueKinds(array $targets): array
    {
        $kinds = [];

        foreach (array_keys($targets) as $label) {
            $label = mb_strtolower($label);

            if (! str_contains($label, 'queue')) {
                continue;
            }

            $kinds[] = str_contains($label, 'ticket') ? 'tickets' : 'conversations';
        }

        return array_values(array_unique($kinds));
    }

    /**
     * How many rows the selected queues will actually hydrate.
     *
     * Pure and public, because the cases worth asserting are ones no test can
     * afford to seed: the interesting one is a desk with tens of thousands of
     * rows, and the arithmetic is where the mistakes live.
     *
     * @param  list<string>  $kinds
     */
    public static function estimatedRows(array $kinds, int $conversations, int $activeCobrowse, int $tickets): int
    {
        $perKind = array_map(fn (string $kind): int => match ($kind) {
            // The conversation queue is capped, so its rows stop growing with
            // the desk -- EXCEPT for conversations with a live cobrowse
            // session, which are hydrated in full on every render to count how
            // many need attention, and which `withActiveCobrowseSession()`
            // never ages out. Taking the cap alone would promise a warning the
            // command does not give and then die in the path it called safe.
            'conversations' => max(min($conversations, ConversationQueueQuery::DISPLAY_LIMIT), $activeCobrowse),
            'tickets' => min($tickets, Ticket::QUEUE_DISPLAY_LIMIT),
            default => 0,
        }, $kinds);

        return $perKind === [] ? 0 : max($perKind);
    }

    /**
     * The warning for a desk this size under this limit, or null for none.
     *
     * Pure, and public, so the rule can be asserted at the sizes that matter
     * rather than at the sizes a test can afford to seed: warning correctly at
     * 50,000 conversations is the case worth proving, and no test is going to
     * write 50,000 rows to prove it.
     *
     * The estimate is ~40KB per row, a straight line through one measured
     * point -- a 50,000-conversation queue needs between 1.5G and 2G. Enough to
     * be useful, and stated as an upper bound because the filtered lanes render
     * a subset of the table it counts.
     */
    public static function memoryWarning(int $rows, ?int $limitBytes, string $limitAsWritten, string $commandName): ?string
    {
        // No limit is not a small limit.
        if ($limitBytes === null) {
            return null;
        }

        $needed = $rows * 40 * 1024;

        if ($limitBytes >= $needed) {
            return null;
        }

        // "Up to", deliberately. This is the whole table, not the rows a given
        // filter returns: the search lane renders only what matches and the
        // assigned lane only what is assigned, so a precise-sounding figure
        // would be wrong for them in the safe direction. An upper bound is the
        // honest shape for a warning whose job is "this may not be enough".
        return sprintf(
            'memory_limit is %s and a queue over %s rows can need up to about %s. '
            .'Re-run with `php -d memory_limit=%dG artisan %s` if it dies.',
            $limitAsWritten,
            ReaderNumber::count($rows),
            ReaderNumber::count((int) round($needed / 1024 / 1024)).'M',
            max(1, (int) ceil($needed / 1024 / 1024 / 1024)),
            $commandName,
        );
    }

    /**
     * The configured limit in bytes, or null when there is not one.
     */
    private function memoryLimitInBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Put the router's current route and request back.
     *
     * By reflection, because `Router` exposes `getCurrentRoute()` and
     * `getCurrentRequest()` and no setter for either -- the properties are
     * assigned during dispatch and nowhere else. Rebinding the router instead
     * would discard every registered route.
     *
     * Failure is swallowed on purpose. If a future framework renames these, the
     * measurement is still correct and the only loss is a restoration nothing
     * in a CLI run depends on; throwing here would fail a benchmark over its
     * own tidying.
     */
    private function restoreRouterTo(?RoutingRoute $route, ?Request $request): void
    {
        $router = app('router');

        foreach (['current' => $route, 'currentRequest' => $request] as $property => $value) {
            try {
                (new ReflectionProperty($router, $property))->setValue($router, $value);
            } catch (Throwable) {
                // Left as the measurement found it.
            }
        }
    }

    /**
     * How much the response actually puts on the wire.
     *
     * A STREAMED response carries no content to ask for -- `getContent()`
     * returns false and the report export measured as zero bytes, which is a
     * published figure that is simply untrue. Streaming it into a buffer is the
     * only way to weigh it, and it is what the client receives.
     *
     * Deliberately not part of the timed run: the buffering is this command's
     * cost, not the page's, and the byte figure comes from the same separate
     * request the query count does.
     */
    private static function realise(Response $response): string
    {
        if (! $response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        // Streamed into the buffer the caller opened. A `StreamedResponse` has
        // no content to ask for -- `getContent()` returns false, and the report
        // export measured as zero bytes and near-zero time until this ran its
        // callback, which is where the work actually is.
        $before = (string) ob_get_contents();

        $response->sendContent();

        return substr((string) ob_get_contents(), strlen($before));
    }

    private function purgeSessions(): void
    {
        $handler = Session::getHandler();

        foreach ($this->sessionIds as $id) {
            try {
                $handler->destroy($id);
            } catch (Throwable $e) {
                $this->warn('Could not remove the session '.$id.': '.$e->getMessage());
            }
        }

        $this->sessionIds = [];
    }

    private function dispatch(Kernel $kernel, User $agent, string $uri): Response
    {
        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn (): User => $agent);

        try {
            return $kernel->handle($request);
        } finally {
            // Every cookie-less request starts a session and persists it, so a
            // measurement left one stored row or file per request behind --
            // four for a single page, and the store is the caller's.
            //
            // RECORDED here and purged at the end, not destroyed here. Doing it
            // in place put the delete inside both windows this command reports:
            // it counted as a query the measured page never issues -- three
            // pages gained exactly one -- and on a database-backed store it sat
            // inside the per-request transaction, so the rollback undid the
            // cleanup and it never removed anything at all.
            if ($request->hasSession()) {
                $this->sessionIds[] = $request->session()->getId();
            }
        }
    }

    private function agent(): ?User
    {
        $email = $this->option('email');

        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        // The seeded OWNER by its exact address. A `like 'desk-agent-%'` also
        // matches `desk-agent-owner@example.test`, which the seeder permits on
        // somebody else's account -- so the command could measure a different
        // account's user and report the figures as this desk's.
        // No fallback to "whoever exists". On an install with real users and no
        // measurement desk, that signed in as somebody's actual account and
        // reported their numbers as the desk's -- where the documented answer
        // is to run the seeder or pass `--email`.
        return User::query()->where('email', 'desk-agent-0@example.test')->first();
    }

    /**
     * The pages worth timing, and why each one is here.
     *
     * @return array<string, string>
     */
    private function targets(User $agent): array
    {
        // Scoped to the measured agent's own account. A global `first()` picks
        // whatever conversation has the highest id, which in a database holding
        // more than one account is one this agent cannot open -- and a 404 is
        // very fast, so it would have been reported as the best number on the
        // page.
        // `Site::visibleToAgent` -- the SAME scope the queue uses -- rather than
        // an account match. An account whose sites carry explicit support-agent
        // assignments has sites this agent cannot see, and a conversation on
        // one of those is a 404 for them even though the account is theirs.
        // The most recently ACTIVE conversation, which is the row at the top of
        // the queue and the one an agent opens. Ordering by id descending picked
        // the OLDEST, because the seeder writes newest-first -- and with the
        // default fixture that last row also carries the balancing message delta
        // and no ticket, so it was the least representative conversation
        // available.
        $visible = Conversation::query()
            ->whereIn('site_id', Site::query()->visibleToAgent($agent)->select('id'))
            ->orderByDesc('last_message_at')
            // `created_at`, not `id`. With `--messages=0` every
            // `last_message_at` is null and the tie-break decides -- and
            // descending id picks the OLDEST, because the seeder writes
            // newest-first.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // An OPEN one, because the queue measured beside it shows open
        // conversations by default -- so the control was a page reached from a
        // lane nobody was timing. Falls back to any conversation on a desk that
        // has closed everything, where measuring something beats measuring
        // nothing.
        $conversation = (clone $visible)->where('status', 'open')->first() ?? $visible->first();

        // The TICKET pages do not need one, and an agent can hold tickets
        // without a visible conversation. Returning nothing when there is no
        // conversation made `--page=ticket` fail on a data shape the product
        // supports, before the filter had a chance to run.
        $targets = [
            // The page an agent opens first and returns to all day.
            'Conversation queue (open)' => '/dashboard/conversations',
            // The lane that accumulates. An open queue is bounded by how far
            // behind the desk is; a closed one grows forever.
            'Conversation queue (closed)' => '/dashboard/conversations?conversation_filter=closed',
            'Conversation queue (search)' => '/dashboard/conversations?conversation_search=refund',
            'Conversation queue (mine)' => '/dashboard/conversations?conversation_filter=assigned_to_me',
            'Ticket queue (open)' => '/dashboard/tickets',
            'Ticket queue (all)' => '/dashboard/tickets?ticket_status=all',
        ];

        // The report tabs, at both ends of the window range they offer. The
        // window is the axis that matters: 7 days and 90 days are different
        // amounts of work over the same desk rather than the same page twice,
        // and the export is the one report path whose cost is not bounded by
        // what a screen can show.
        //
        // ADMIN ONLY, because `AgentReportController` aborts 403 for anyone
        // else -- and a 403 fails the whole run, since a page that did not
        // render is not a measurement. Adding them unconditionally broke
        // `--email` against an ordinary agent, which was a supported way to
        // measure before this. An agent who cannot open the reports simply does
        // not measure them.
        if ($agent->isAdmin()) {
            $targets['Reports (7 days)'] = '/dashboard/reports?report_days=7';
            $targets['Reports (90 days)'] = '/dashboard/reports?report_days=90';
            $targets['Reports export (90 days)'] = '/dashboard/reports/export?report_days=90';
        }

        if ($conversation !== null) {
            // The one page whose cost should NOT grow with the desk, and the
            // control that says so when the others do.
            $targets['Conversation detail'] = '/dashboard/conversations/'.$conversation->support_code;
        }

        return $targets;
    }

    /**
     * Narrow the set to what `--page` asked for.
     *
     * For an operator re-measuring one page after changing it, rather than
     * sitting through the closed lane again to see whether the ticket queue
     * moved. Matched on a substring of the name, case-insensitively, because
     * the names are for reading rather than for typing exactly.
     *
     * @param  array<string, string>  $targets
     * @return array<string, string>
     */
    private function onlyRequested(array $targets): array
    {
        /** @var list<string> $wanted */
        $wanted = (array) $this->option('page');

        if ($wanted === []) {
            return $targets;
        }

        $matched = array_filter(
            $targets,
            fn (string $label): bool => collect($wanted)
                ->contains(fn (string $needle): bool => str_contains(mb_strtolower($label), mb_strtolower($needle))),
            ARRAY_FILTER_USE_KEY,
        );

        if ($matched === []) {
            // STDERR, so `--json` stays parseable. A notice printed above the
            // document makes the output unreadable to every consumer of it,
            // which is a strange way to be helpful.
            $notice = 'No page matches '.implode(', ', $wanted).'. Measuring all of them.';

            if ($this->option('json')) {
                // `OutputStyle::getErrorOutput()` is protected, so the raw
                // Symfony output underneath is where stderr lives.
                $stderr = $this->getOutput()->getOutput();

                if ($stderr instanceof ConsoleOutputInterface) {
                    $stderr->getErrorOutput()->writeln('<comment>'.$notice.'</comment>');
                }

                return $targets;
            }

            $this->components->warn($notice);

            return $targets;
        }

        return $matched;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return ReaderNumber::decimal($bytes / 1048576, 1).' MB';
        }

        return ReaderNumber::count((int) round($bytes / 1024)).' KB';
    }
}
