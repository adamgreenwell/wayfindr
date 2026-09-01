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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        {--json : Emit machine-readable rows instead of a table}';

    protected $description = 'Time the dashboard\'s heaviest pages against the data currently in the database.';

    public function handle(Kernel $kernel): int
    {
        $agent = $this->agent();

        if ($agent === null) {
            $this->components->error('No agent to measure as. Run `wayfindr:seed-desk` first, or pass --email.');

            return self::FAILURE;
        }

        Auth::login($agent);

        $targets = $this->targets($agent);

        if ($targets === []) {
            $this->components->error('No conversation to measure against. Run `wayfindr:seed-desk` first.');

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

        // TIMED runs are uninstrumented. Laravel's query log allocates and
        // retains an entry per query, so leaving it on inside the measured
        // interval charges the page for the measuring -- and the overhead grows
        // with query count, which is exactly the axis the ticket queue's N+1
        // sits on. Timing it instrumented would have inflated the one number
        // that is evidence for the finding.
        for ($run = 0; $run < $runs; $run++) {
            $startedAt = microtime(true);
            $response = $this->send($kernel, $agent, $uri);
            $timings[] = (microtime(true) - $startedAt) * 1000;

            $bytes = strlen((string) $response->getContent());
            $status = $response->getStatusCode();
        }

        // Counted separately, once, and not timed.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->send($kernel, $agent, $uri);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

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
     * One request, with anything it writes rolled back.
     *
     * Measuring is meant to be an observation. The conversation detail page is
     * not a read: `show()` marks notifications read and marks the conversation
     * read for the viewer, and with a cobrowse replay present it records a
     * `cobrowse.preview_viewed` audit event. Run with `--email` against a real
     * agent -- which is exactly what an operator measuring their own install
     * would do -- a benchmark silently cleared their notifications and left
     * audit entries attributed to them.
     *
     * A transaction round every request, always rolled back, makes that
     * impossible for this page and for any page added to the list later. The
     * overhead is uniform across the set and is the price of a tool that cannot
     * change what it is measuring.
     */
    private function send(Kernel $kernel, User $agent, string $uri): Response
    {
        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn (): User => $agent);

        DB::beginTransaction();

        try {
            return $kernel->handle($request);
        } finally {
            DB::rollBack();
        }
    }

    private function agent(): ?User
    {
        $email = $this->option('email');

        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return User::query()->where('email', 'like', 'desk-agent-%@example.test')->orderBy('id')->first()
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

        if ($conversation === null) {
            return [];
        }

        return [
            // The page an agent opens first and returns to all day.
            'Conversation queue (open)' => '/dashboard/conversations',
            // The lane that accumulates. An open queue is bounded by how far
            // behind the desk is; a closed one grows forever.
            'Conversation queue (closed)' => '/dashboard/conversations?conversation_filter=closed',
            'Conversation queue (search)' => '/dashboard/conversations?conversation_search=refund',
            'Conversation queue (mine)' => '/dashboard/conversations?conversation_filter=assigned_to_me',
            'Ticket queue (open)' => '/dashboard/tickets',
            'Ticket queue (all)' => '/dashboard/tickets?ticket_status=all',
            // The one page whose cost should NOT grow with the desk, and the
            // control that says so when the others do.
            'Conversation detail' => '/dashboard/conversations/'.$conversation->support_code,
        ];
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return ReaderNumber::decimal($bytes / 1048576, 1).' MB';
        }

        return ReaderNumber::count((int) round($bytes / 1024)).' KB';
    }
}
