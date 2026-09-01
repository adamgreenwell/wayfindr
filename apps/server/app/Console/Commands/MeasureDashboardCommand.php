<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Support\ReaderNumber;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\HttpFoundation\Response;

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
    protected $signature = 'wayfindr:measure-dashboard
        {--email= : The agent to measure as; defaults to the seeded desk owner}
        {--runs=3 : Measured runs per page, after one warm-up}
        {--page=* : Measure only pages whose name contains this, e.g. --page=ticket}
        {--json : Emit machine-readable rows instead of a table}';

    protected $description = 'Time the dashboard\'s heaviest pages against the data currently in the database.';

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

        Auth::login($agent);

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

            $caller === null ? Auth::logout() : Auth::login($caller);

            App::setLocale($callerLocale);
        }
    }

    /**
     * Named around `Command::run()`, which is public and belongs to the base
     * class -- redeclaring it private is a fatal before anything runs.
     */
    private function measureAll(Kernel $kernel, User $agent): int
    {

        $targets = $this->onlyRequested($this->targets($agent));

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
        $this->send($kernel, $agent, $uri);

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
            // Savepoint opened BEFORE the clock and rolled back after it, so
            // the isolation is not part of what the page is charged for.
            DB::beginTransaction();

            try {
                $startedAt = microtime(true);
                $response = $this->dispatch($kernel, $agent, $uri);
                $timings[] = (microtime(true) - $startedAt) * 1000;
            } finally {
                DB::rollBack();
            }

            $bytes = strlen((string) $response->getContent());

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
        DB::beginTransaction();

        try {
            return $this->dispatch($kernel, $agent, $uri);
        } finally {
            DB::rollBack();
        }
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
    private function dispatch(Kernel $kernel, User $agent, string $uri): Response
    {
        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn (): User => $agent);

        return $kernel->handle($request);
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
        return User::query()->where('email', 'desk-agent-0@example.test')->first()
            ?? User::query()->orderBy('id')->first();
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
        $conversation = Conversation::query()
            ->whereIn('site_id', Site::query()->visibleToAgent($agent)->select('id'))
            ->orderByDesc('id')
            ->first();

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
