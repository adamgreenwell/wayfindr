<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\OperatorSetting;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\ReaderNumber;
use App\Support\Reporting\ReportingWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A desk's worth of support data, for measuring against.
 *
 * Wayfindr has never been measured with anything in it (#796). The disposable-VM
 * matrix proves it installs, upgrades, backs up and restores, and every one of
 * those is a correctness proof on an empty install. Nothing establishes how the
 * queue, the reports or the conversation page behave once a real desk has been
 * running for a year, which is the first question an operator asks and the one
 * a `1.0` is a promise about.
 *
 * This writes that year. It is a MEASUREMENT fixture, not a demo: the data is
 * shaped so the queries under test do real work -- statuses and assignees and
 * categories spread out, subjects varied enough that a search is not a full
 * match, conversations distributed across the whole window so a ninety-day
 * report has buckets to fill -- and it is deliberately dull to read.
 *
 * **Bulk inserts, not factories.** Fifty thousand conversations through the
 * model layer is hours; through `insert()` in chunks it is seconds. The cost is
 * that model events do not fire, which is correct here: this is seeding a
 * database, not exercising the application, and a seeder that broadcast fifty
 * thousand presence events would measure the seeder.
 *
 * Everything lands in its own account so it cannot be confused with real data,
 * and `--fresh` removes exactly that account rather than truncating tables.
 */
final class SeedDeskCommand extends Command
{
    protected $signature = 'wayfindr:seed-desk
        {--conversations=5000 : How many conversations to write}
        {--months=12 : The window they are spread across}
        {--agents=8 : Agents on the account}
        {--sites=3 : Sites the conversations are split between}
        {--messages=6 : Average messages per conversation}
        {--fresh : Delete a previously seeded desk first}
        {--force : Required to run when the app is in production}';

    protected $description = 'Write a desk-sized account to measure the dashboard against.';

    /**
     * The account this command owns. Nothing outside it is ever touched.
     */
    private const SLUG = 'wayfindr-measurement-desk';

    /**
     * Rows per INSERT. High enough that the round trips stop mattering, low
     * enough to stay well inside the placeholder limits both drivers impose --
     * SQLite's default is 32766 bound parameters, and a conversation row binds
     * eleven of them.
     */
    private const CHUNK = 500;

    /**
     * The marks this command leaves on what it creates.
     *
     * `accounts` has nowhere to write a provenance flag -- it is `name`, `slug`
     * and timestamps -- so ownership is read from the shape instead. The
     * ordinary site key generator produces `site_` plus thirty-two random
     * characters and never this prefix.
     */
    private const NAME = 'Measurement Desk';

    /**
     * What a visitor writes when they answer. Deliberately dull and varied in
     * length, because the report renders these and a fixture of identical
     * one-word strings measures a narrower row than a real desk produces.
     */
    private const RATING_COMMENTS = [
        'Sorted quickly, thank you.',
        'Took a while to get going but the answer was right in the end.',
        'Still not sure this is fixed.',
        'Clear explanation, no complaints.',
        'Had to repeat myself a few times before it was understood.',
        'Exactly what I needed.',
    ];

    /**
     * How many replies a ticket can carry, and so how many of its
     * conversation's agent messages are worth loading.
     */
    private const MAX_TICKET_REPLIES = 3;

    private const SITE_KEY_PREFIX = 'site_desk_';

    private const AGENT_PREFIX = 'desk-agent-';

    private const AGENT_SUFFIX = '@example.test';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('This writes tens of thousands of rows. Re-run with --force if that is really what you want here.');

            return self::FAILURE;
        }

        $conversations = max(1, (int) $this->option('conversations'));
        $months = max(1, (int) $this->option('months'));
        $agentCount = max(1, (int) $this->option('agents'));
        $siteCount = max(1, (int) $this->option('sites'));
        $messagesEach = max(0, (int) $this->option('messages'));

        $startedAt = microtime(true);
        $written = [];

        try {
            // EVERY refusal before the destructive step. `--fresh` deleted the
            // existing desk first, so a run that could never have finished --
            // a support code or an agent address held by another account --
            // cost the operator the fixture they already had on the way to
            // failing.
            //
            // Both checks exclude what belongs to this desk, so they are
            // answering the same question before and after the delete.
            $existing = Account::query()->where('slug', self::SLUG)->first();

            $this->refuseCollidingSupportCodes($conversations);
            $this->refuseTakenAddresses($existing?->id, $agentCount);

            if ($this->option('fresh')) {
                $this->components->task('Removing the previous desk', function (): bool {
                    // The account cascade takes its sites, and theirs takes the
                    // visitors, conversations, messages and tickets underneath. One
                    // delete rather than a truncate, so a real account sitting in
                    // the same database is untouched.
                    //
                    // Users are NOT part of that cascade: `users.account_id` is
                    // `nullOnDelete()`, so deleting the account detaches them and
                    // leaves them behind. Reseeding with fewer agents would then
                    // strand sign-in-capable accounts with this command's known
                    // password and no account at all -- which is a worse thing to
                    // leave on a machine than the rows it was asked to remove.
                    //
                    // Scoped to THIS account and taken first, while the link still
                    // exists. Matching on the address alone is global, and would
                    // delete a real person who happens to hold a `desk-agent-`
                    // address on another account -- which is the same promise this
                    // command makes about the rest of the database, broken by the
                    // fix for the orphans.
                    $previous = Account::query()->where('slug', self::SLUG)->first();

                    if ($previous === null) {
                        return true;
                    }

                    $this->refuseUnlessSeeded($previous);

                    User::query()
                        ->where('account_id', $previous->id)
                        ->where('email', 'like', 'desk-agent-%@example.test')
                        ->delete();

                    $previous->delete();

                    return true;
                });
            }

            $desk = $this->desk($agentCount, $siteCount);

            $written['visitors'] = $this->measure('Visitors', fn (): int => $this->seedVisitors($desk, $conversations, $months, $messagesEach));
            $written['conversations'] = $this->measure('Conversations', fn (): int => $this->seedConversations($desk, $conversations, $months, $messagesEach));
            $written['messages'] = $this->measure('Messages', fn (): int => $this->seedMessages($desk, $messagesEach));
            $this->syncLastMessageAt($desk);
            $this->syncVisitorLastSeenAt($desk);
            $written['tickets'] = $this->measure('Tickets', fn (): int => $this->seedTickets($desk));
            $written['read states'] = $this->measure('Read states', fn (): int => $this->seedReadStates($desk));
            $written['lifecycle events'] = $this->measure('Lifecycle events', fn (): int => $this->seedLifecycleHistory($desk));
            $written['ratings'] = $this->measure('Ratings', fn (): int => $this->seedRatings($desk));
        } catch (Throwable $failure) {
            $this->components->error('Seeding stopped: '.$failure->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Desk</>', self::SLUG);

        foreach ($written as $what => $count) {
            $this->components->twoColumnDetail(ucfirst($what), ReaderNumber::count((int) $count));
        }

        $this->components->twoColumnDetail('Window', $months.' months');

        $this->warnAboutRecordingBoundaries($months);
        $this->components->twoColumnDetail('Took', ReaderNumber::decimal(microtime(true) - $startedAt, 1).'s');

        $this->newLine();
        $this->components->info('Sign in as '.$desk['agents'][0]->email.' with the password `password`.');

        return self::SUCCESS;
    }

    /**
     * The account, its sites and its agents. Small enough for the model layer.
     *
     * @return array{account: Account, sites: list<Site>, agents: list<User>}
     */
    private function desk(int $agentCount, int $siteCount): array
    {
        // Checked on EVERY run, not only under `--fresh`. Without `--fresh` this
        // reuses whatever account holds the slug -- renaming it, and adding
        // `desk-agent-0` to it as OWNER with the password this command prints
        // on success. That is an account takeover rather than a seeding, and
        // confining the provenance check to the delete path left it wide open.
        $existing = Account::query()->where('slug', self::SLUG)->first();

        if ($existing !== null) {
            $this->refuseUnlessSeeded($existing);

            // A desk is already here, and this run would reuse its sites and
            // re-insert the same `desk-visitor-` identifiers -- which the
            // `(site_id, anonymous_id)` unique index refuses. That failure
            // arrives AFTER the agents' passwords have been rehashed and the
            // sites' public keys replaced, so forgetting the flag both fails
            // and half-rewrites the fixture the operator already had.
            //
            // Refused up front instead, saying which flag to add.
            if (Site::query()->where('account_id', $existing->id)->exists()) {
                throw new RuntimeException(
                    'A measurement desk is already seeded. Re-run with --fresh to replace it, '
                    .'which removes the previous one first.'
                );
            }
        }

        $account = Account::query()->updateOrCreate(
            ['slug' => self::SLUG],
            ['name' => self::NAME],
        );

        $agents = [];

        for ($i = 0; $i < $agentCount; $i++) {
            $agents[] = User::query()->updateOrCreate(
                ['email' => self::agentAddress($i), 'account_id' => $account->id],
                [
                    // One owner so the account is manageable, the rest agents,
                    // because the queue's assignee filter is only interesting
                    // when several people can hold work.
                    'account_role' => $i === 0 ? AccountRole::Owner : AccountRole::Agent,
                    'name' => 'Desk Agent '.($i + 1),
                    'password' => Hash::make('password'),
                ],
            );
        }

        $sites = [];

        for ($i = 0; $i < $siteCount; $i++) {
            $sites[] = Site::query()->updateOrCreate(
                ['account_id' => $account->id, 'name' => 'Desk Site '.($i + 1)],
                [
                    'public_key' => 'site_desk_'.$i.'_'.Str::lower(Str::random(16)),
                    'domain' => 'desk-'.$i.'.example.test',
                ],
            );
        }

        return ['account' => $account, 'sites' => $sites, 'agents' => $agents];
    }

    /**
     * One visitor per conversation, spread across the sites.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     */
    private function seedVisitors(array $desk, int $conversations, int $months, int $messagesEach): int
    {
        // The SAME headroom the conversations reserve, plus a margin. Visitor
        // `i` and conversation `i` are placed by the same formula over their own
        // clock, so a window five minutes earlier puts every visitor ahead of
        // the conversation they belong to -- which was not true while the
        // conversations shifted back for their messages and the visitors did
        // not.
        $now = Carbon::now()->subMinutes($messagesEach + 10);

        // Presence is relative to REAL time, not to the shifted window. Inside
        // two minutes is `active`, and measured from a window pushed back for
        // the message span, "a minute ago" is already too old to be -- so every
        // visitor collapsed into the two older states.
        $present = Carbon::now();
        $siteCount = count($desk['sites']);
        $written = 0;
        $rows = [];

        for ($i = 0; $i < $conversations; $i++) {
            $seenAt = $now->copy()->subMinutes((int) ($i * $months * 43800 / max(1, $conversations)));

            $rows[] = [
                'site_id' => $desk['sites'][$i % $siteCount]->id,
                'anonymous_id' => 'desk-visitor-'.$i,
                // Two thirds named, because the queue renders a name where it
                // has one and an identifier where it does not, and those are
                // different amounts of work.
                'name' => $i % 3 === 0 ? null : 'Visitor '.$i,
                'email' => $i % 3 === 0 ? null : 'visitor'.$i.'@example.test',
                'metadata' => json_encode(['last_page_url' => 'https://desk-'.($i % $siteCount).'.example.test/page/'.($i % 50)]),
                // The WEB sighting, which is what presence is computed from --
                // `last_seen_at` alone leaves every visitor `not_reported`, so
                // the queue never rendered an active or recent marker and the
                // presence filter had one value to choose between.
                //
                // Spread across all four states: inside two minutes is active,
                // inside fifteen is recent, older is quiet, and absent is not
                // reported at all.
                'last_web_seen_at' => $webSeenAt = match (self::mix($i, 'presence', 4)) {
                    0 => $present->copy()->subMinute(),
                    1 => $present->copy()->subMinutes(7),
                    2 => $present->copy()->subHours(3),
                    default => null,
                },
                // The LATER of the two, which is what `Visitor::saving()` keeps
                // true and a bulk insert bypasses. Without it a visitor shows
                // as active on the live board while the directory says they
                // were last seen months ago.
                'last_seen_at' => $webSeenAt !== null && $webSeenAt->greaterThan($seenAt) ? $webSeenAt : $seenAt,
                // `Visitor::booted()` starts a visit the first time a website
                // sighting is recorded, and a bulk insert bypasses it -- so
                // every present visitor had a null visit start and the live
                // board's "on site for" column had nothing to measure from.
                //
                // Varied, so the column is not one repeated duration.
                'current_visit_started_at' => $visitStartedAt = $webSeenAt?->copy()->subMinutes(self::mix($i, 'visit', 45) + 1),
                // Created at its EARLIEST moment. A visit that started before
                // the visitor existed is a timeline no surface can render
                // sensibly, and the web sighting or the visit start can both
                // precede the historical `last_seen_at` this row was placed at.
                'created_at' => $createdAt = collect([$seenAt, $webSeenAt, $visitStartedAt])
                    ->filter()
                    ->min(),
                'updated_at' => $createdAt,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('visitors')->insert($rows);
                $written += count($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('visitors')->insert($rows);
            $written += count($rows);
        }

        return $written;
    }

    /**
     * Conversations across the whole window, most of them closed.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     */
    private function seedConversations(array $desk, int $conversations, int $months, int $messagesEach): int
    {
        // Headroom for the messages, which are written a minute apart from the
        // conversation's own start. Without it the newest conversation's last
        // message lands in the FUTURE -- and `last_message_at` follows it, so
        // the queue reported activity that had not happened yet.
        $now = Carbon::now()->subMinutes($messagesEach + 5);
        $siteCount = count($desk['sites']);
        $agentCount = count($desk['agents']);

        // Read once. Matching visitors to conversations by index keeps this a
        // single pass and means each conversation has its own visitor, which is
        // what makes the queue's joins do the work they do in production.
        $visitorIds = DB::table('visitors')
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('anonymous_id', 'like', 'desk-visitor-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($visitorIds === []) {
            return 0;
        }

        $subjects = $this->subjects();

        // `conversations.support_code` is globally unique, so a real or legacy
        // row already holding one of these deterministic codes aborts the
        // insert -- after the account, agents, sites and visitors are written,
        // and with `--fresh` unable to help because the conflicting row is
        // somebody else's. Checked first, so the run fails before it builds
        // anything.
        $written = 0;
        $rows = [];

        for ($i = 0; $i < $conversations; $i++) {
            $openedAt = $now->copy()->subMinutes((int) ($i * $months * 43800 / max(1, $conversations)));

            // Roughly one in six still open, which is the shape of a desk that
            // is keeping up. A queue of nothing but closed rows would make the
            // default view -- the one an agent actually opens -- the cheapest
            // query here rather than the most expensive.
            $open = self::mix($i, 'status', 6) === 0;

            $rows[] = [
                'site_id' => $desk['sites'][$i % $siteCount]->id,
                'visitor_id' => $visitorIds[$i % count($visitorIds)],
                // Independent of status, or the unassigned-OPEN lane -- the
                // one an agent actually works from -- never gets a row.
                'assigned_agent_id' => self::mix($i, 'assignee', 2) === 0
                    ? $desk['agents'][self::mix($i, 'agent', $agentCount)]->id
                    : null,
                'support_code' => $this->supportCode($i),
                'status' => $open ? 'open' : 'closed',
                'subject' => $subjects[$i % count($subjects)].' '.$i,
                'metadata' => json_encode([]),
                // Provisional. Corrected from the messages actually written
                // once they exist -- and left NULL when none were asked for,
                // rather than claiming activity `--messages=0` never created.
                'last_message_at' => $messagesEach === 0 ? null : $openedAt->copy()->addMinutes(30),
                // Never later than now, the same clamp the tickets carry. The
                // newest conversation opens minutes before seeding, so four
                // hours later is several hours from now -- and the closed queue
                // reported a resolution that has not happened.
                'closed_at' => $open ? null : $openedAt->copy()->addHours(4)->min(Carbon::now()),
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('conversations')->insert($rows);
                $written += count($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('conversations')->insert($rows);
            $written += count($rows);
        }

        return $written;
    }

    /**
     * Messages under each conversation, alternating visitor and agent.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     */
    private function seedMessages(array $desk, int $messagesEach): int
    {
        if ($messagesEach === 0) {
            return 0;
        }

        $agentIds = array_map(fn (User $agent): int => $agent->id, $desk['agents']);
        $written = 0;
        $deviation = 0;
        $seen = 0;

        $total = DB::table('conversations')
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('support_code', 'like', 'WF-DESK-%')
            ->count();

        // Streamed in chunks rather than plucked whole: at fifty thousand
        // conversations the id list alone is the largest thing this command
        // would hold, and there is no reason to hold it.
        DB::table('conversations')
            // Scoped to this desk's SITES, not just the support-code prefix.
            // The prefix is global, so a conversation on somebody else's
            // account carrying it -- a legacy row, a hand-made one -- would
            // have had synthetic messages attached to it, attributed to
            // measurement-desk agents.
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('support_code', 'like', 'WF-DESK-%')
            ->orderBy('id')
            ->select(['id', 'visitor_id', 'created_at', 'support_code'])
            ->chunk(self::CHUNK, function ($conversations) use ($messagesEach, $agentIds, $total, &$written, &$deviation, &$seen): void {
                $rows = [];

                foreach ($conversations as $index => $conversation) {
                    $seen++;
                    $startedAt = Carbon::parse($conversation->created_at);

                    // Varied, not uniform. Every conversation holding exactly
                    // six messages makes the detail page's cost a constant, and
                    // the long ones are where it is worth knowing.
                    //
                    // The spread is narrowed rather than CLAMPED, so the average
                    // stays what was asked for. `max(1, $n + $i % 5 - 2)` gave
                    // `--messages=1` the counts 1,1,1,2,3 -- an average of 1.6
                    // against an advertised 1, which is exactly the wrong thing
                    // to get wrong in a fixture whose size is reported.
                    // The TOTAL is exact, not merely balanced over a whole
                    // cycle. Ordering the deltas so prefixes stay near zero was
                    // an improvement and still left `--conversations=2` at 6.5
                    // per conversation, because no fixed cycle sums to zero at
                    // every length.
                    //
                    // So the running deviation is carried, and the LAST
                    // conversation takes whatever cancels it. With a spread of
                    // at most two the correction never drives a count below one.
                    $spread = max(0, min(2, $messagesEach - 1));
                    $deltas = $spread === 0 ? [0] : [0, $spread - 1, -($spread - 1), $spread, -$spread];

                    $delta = $seen === $total
                        ? -$deviation
                        : $deltas[$index % count($deltas)];

                    $deviation += $delta;
                    $count = max(1, $messagesEach + $delta);

                    // Roughly a third, independent of everything else.
                    $unread = self::mix($this->seededIndex((string) $conversation->support_code), 'unread', 3) === 0;

                    for ($m = 0; $m < $count; $m++) {
                        $fromVisitor = $m % 2 === 0;

                        $rows[] = [
                            'conversation_id' => $conversation->id,
                            'sender_type' => $fromVisitor ? Visitor::class : User::class,
                            'sender_id' => $fromVisitor ? $conversation->visitor_id : $agentIds[$m % count($agentIds)],
                            'type' => 'text',
                            // The SUPPORT CODE, not the database id. Deleting the
                            // desk does not reset the sequence, so a reseed hands
                            // the same conversation a different id and a wider one
                            // as the install ages -- and the queue renders this
                            // body for every row, so the response BYTES moved with
                            // sequence history rather than with the options the
                            // fixture was given. The baseline calls those bytes
                            // exact. The support code is fixed width and depends
                            // only on the seeded index.
                            'body' => 'Message '.$m.' on conversation '.$conversation->support_code.'. '
                                .'Enough words that a body column holds something worth reading past.',
                            'metadata' => json_encode([]),
                            // The last message on an open conversation is unread,
                            // whoever sent it -- and the sender decides which
                            // branch that exercises. An unseen VISITOR message is
                            // what the queue's attention lanes are computed from;
                            // an unseen AGENT reply is the read-receipt branch on
                            // the detail page, which `seen_at` actually describes.
                            //
                            // Requiring `$fromVisitor` here left every agent reply
                            // stamped, so the receipt branch never rendered in any
                            // measurement.
                            'seen_at' => $unread && $m === $count - 1
                                ? null
                                : $startedAt->copy()->addMinutes($m + 1),
                            'created_at' => $startedAt->copy()->addMinutes($m),
                            'updated_at' => $startedAt->copy()->addMinutes($m),
                        ];

                        // Flushed INSIDE the loop, not after the chunk. A long
                        // fixture -- `--conversations=500 --messages=400`, which
                        // is what measuring a heavy detail page needs -- held
                        // two hundred thousand associative rows and several
                        // Carbon objects each before writing any of them.
                        if (count($rows) >= self::CHUNK) {
                            DB::table('conversation_messages')->insert($rows);
                            $written += count($rows);
                            $rows = [];
                        }
                    }
                }

                if ($rows !== []) {
                    DB::table('conversation_messages')->insert($rows);
                    $written += count($rows);
                }
            });

        return $written;
    }

    /**
     * A ticket on roughly one conversation in four.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     */
    private function seedTickets(array $desk): int
    {
        $agentIds = array_map(fn (User $agent): int => $agent->id, $desk['agents']);
        $categories = ['question', 'bug', 'billing', 'access', 'task', 'other'];
        $priorities = ['low', 'normal', 'high', 'urgent'];
        $statuses = ['open', 'pending', 'closed'];
        $written = 0;

        DB::table('conversations')
            // Same scoping as the messages pass, and for the same reason: this
            // one would otherwise raise a ticket ON the measurement account
            // pointing at another account's conversation.
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('support_code', 'like', 'WF-DESK-%')
            ->orderBy('id')
            ->select(['id', 'site_id', 'visitor_id', 'created_at', 'subject', 'support_code'])
            ->chunk(self::CHUNK, function ($conversations) use ($desk, $agentIds, $categories, $priorities, $statuses, &$written): void {
                $rows = [];

                foreach ($conversations as $index => $conversation) {
                    if ($index % 4 !== 0) {
                        continue;
                    }

                    // Salted per attribute rather than cycled on one counter --
                    // see `mix()` for the three ways the counter went wrong.
                    //
                    // Keyed on the conversation's own index, not a running
                    // count, so a ticket keeps its shape across a reseed for the
                    // same reason the messages and read states do.
                    $n = $this->seededIndex((string) $conversation->support_code);

                    $raisedAt = Carbon::parse($conversation->created_at);
                    // Its OWN salt. Sharing `status` with the conversation
                    // coupled the two lifecycles: `mix($n, 'status', 6)` decides
                    // whether the conversation is open, and the same hash modulo
                    // three then decided the ticket -- so a ticket on an open
                    // conversation was never open itself.
                    $status = $statuses[self::mix($n, 'ticket_status', count($statuses))];
                    $closedAt = $status === 'closed'
                        ? $raisedAt->copy()->addDays(2)->min(Carbon::now())
                        : null;

                    $rows[] = [
                        'account_id' => $desk['account']->id,
                        'site_id' => $conversation->site_id,
                        'conversation_id' => $conversation->id,
                        'requester_id' => $conversation->visitor_id,
                        // A third unassigned, and independent of status, so the
                        // open queue holds assigned AND unassigned rows.
                        'assignee_id' => self::mix($n, 'assignee', 3) === 0
                            ? null
                            : $agentIds[self::mix($n, 'agent', count($agentIds))],
                        'status' => $status,
                        'priority' => $priorities[self::mix($n, 'priority', count($priorities))],
                        'category' => $categories[self::mix($n, 'category', count($categories))],
                        'subject' => (string) $conversation->subject,
                        'description' => 'Raised from conversation '.$conversation->support_code.'.',
                        'metadata' => json_encode([]),
                        // Never later than now. A recent conversation raised
                        // minutes ago was being closed two days from now, so
                        // the ticket queue reported a resolution that has not
                        // happened.
                        'closed_at' => $closedAt,
                        'created_at' => $raisedAt,
                        // A real closure goes through an Eloquent `update()`,
                        // which advances this. The ticket queue orders by it,
                        // so leaving it at the raise time filed every closure
                        // under the day the ticket was opened.
                        'updated_at' => $closedAt ?? $raisedAt,
                    ];
                }

                if ($rows === []) {
                    return;
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('tickets')->insert($chunk);
                    $written += count($chunk);
                }
            });

        return $written;
    }

    /**
     * A deterministic, decorrelated value for one attribute of one row.
     *
     * Modular arithmetic on a shared counter is what this replaces, and it went
     * wrong three separate ways before I stopped using it:
     *
     *   - Tickets are taken every FOURTH conversation, so `id % 4` was the same
     *     number for every ticket and all of them shared one priority.
     *   - A conversation was open when `$i % 6 === 0`, which makes `$i` even --
     *     so the `$i % 2` assignment rule assigned every open row and the
     *     unassigned-open lane, which is the one an agent works from, never
     *     existed.
     *   - Ticket status and null-assignment both used `% 3`, so every open
     *     ticket was unassigned and every closed one assigned, and `% 6`
     *     categories were pinned to a status.
     *
     * Each of those is the same mistake: two attributes derived from one
     * counter are related whether or not the moduli look unrelated. Salting the
     * counter per attribute fixes it, and keeps the fixture reproducible, which
     * `random_int` would not.
     *
     * **The salt is not enough on its own, which is the fourth version of this
     * bug.** `crc32($salt.':'.$n) % 6` looked decorrelated and is not: CRC32 is
     * a linear checksum and its LOW BITS stay related across salts, so at fifty
     * thousand rows the open lane came out 8,309 assigned against 18
     * unassigned. A hash whose output is uniform is required, not merely a
     * different input -- md5 over the same string gives 4,247 against 4,172.
     *
     * Cryptographic strength is irrelevant here; distribution is the whole
     * requirement.
     *
     * Public and static so that requirement can be asserted directly. Reaching
     * it through the seeder means seeding fifty thousand rows to see a skew
     * that only appears at that size -- and a test that seeds a hundred and
     * sixty passes with CRC32 in place, which is exactly what happened.
     */
    public static function mix(int $n, string $attribute, int $of): int
    {
        return (int) (hexdec(substr(md5($attribute.':'.$n), 0, 8)) % $of);
    }

    /**
     * Stop unless the account at this slug is one THIS command made.
     *
     * A slug is user-selectable -- `wayfindr:bootstrap` takes an arbitrary one
     * -- so an account carrying it is not evidence that this command created
     * it, and `--fresh` cascades through every site, visitor, conversation and
     * ticket underneath. Deleting a real desk because it chose the same name is
     * the worst thing in this file.
     *
     * Provenance comes from the SHAPE, because `accounts` has nowhere to write
     * a marker -- it is `name`, `slug` and timestamps, and adding a column to
     * the product's schema for a measurement command is the wrong trade. Every
     * site this command creates carries a `site_desk_` public key, which the
     * ordinary key generator (`site_` plus thirty-two random characters) does
     * not produce, and every user it creates is a `desk-agent-` address.
     *
     * An account with no sites and no users passes: that is what a half-made
     * desk looks like after an interrupted run, and refusing to clean it up
     * would leave the operator stuck.
     */
    private function refuseUnlessSeeded(Account $account): void
    {
        // Compared in PHP, not with LIKE. `_` is a single-character WILDCARD in
        // SQL, so `site_desk_%` also matches `site-desk-legacy` -- and the
        // pattern that was supposed to prove this account is ours would have
        // said yes to somebody else's site and let `--fresh` delete their desk.
        //
        // One account holds a handful of sites and agents, so exact prefix
        // matching costs nothing and cannot be read two ways.
        $foreignSites = Site::query()
            ->where('account_id', $account->id)
            ->pluck('public_key')
            ->reject(fn (?string $key): bool => is_string($key) && str_starts_with($key, self::SITE_KEY_PREFIX))
            ->count();

        $foreignUsers = User::query()
            ->where('account_id', $account->id)
            ->pluck('email')
            ->reject(fn (?string $email): bool => is_string($email) && self::isSeededAgentAddress($email))
            ->count();

        // An empty account is only ours if it is also NAMED as ours. Allowing
        // any empty account through was meant to unblock an interrupted first
        // run, and it let a legitimate but not-yet-configured account at this
        // slug be renamed and adopted -- which is the same failure the
        // provenance check exists to prevent, wearing the shape of a kindness.
        //
        // An interrupted run always leaves the name behind, because the account
        // is created with it before anything else happens.
        if ($foreignSites === 0 && $foreignUsers === 0 && $account->name === self::NAME) {
            return;
        }

        throw new RuntimeException(
            'The account at `'.self::SLUG.'` holds '.$foreignSites.' site(s) and '.$foreignUsers
            .' user(s) this command did not create, so it is somebody\'s real desk rather than a '
            .'previous measurement. Refusing to delete it. Rename that account if you want the slug.'
        );
    }

    /**
     * Stop if a `desk-agent-` address belongs to somebody else.
     *
     * `users.email` is globally unique, so `updateOrCreate` keyed on the
     * address does not create a second user -- it MOVES the existing one onto
     * this account. A real person holding one of these addresses would have
     * been quietly reassigned to a desk whose password this command prints.
     *
     * Failing is the correct answer to "somebody already holds the address I
     * need": it is recoverable, and taking over their account is not.
     */
    private function refuseTakenAddresses(?int $accountId, int $agentCount): void
    {
        // The addresses this run would actually create, not every address
        // shaped like one. A prefix match rejected `desk-agent-999@example.test`
        // held by an unrelated account, which a default eight-agent seed never
        // touches -- so one stray high-index address blocked seeding for good.
        //
        // Built from the same helper the creation uses, because the check and
        // the thing it is checking disagreeing is how this went wrong.
        $planned = array_map(self::agentAddress(...), range(0, $agentCount - 1));

        $taken = User::query()
            ->whereIn('email', $planned)
            ->when(
                $accountId === null,
                fn ($query) => $query,
                fn ($query) => $query->where(fn ($inner) => $inner->whereNull('account_id')->orWhere('account_id', '!=', $accountId)),
            )
            ->pluck('email');

        if ($taken->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'These addresses belong to a user outside the measurement desk, and this command '
            .'would take them over: '.$taken->implode(', ').'. Move or remove them first.'
        );
    }

    /**
     * The address this command hands to the agent at `$index`.
     */
    private static function agentAddress(int $index): string
    {
        return self::AGENT_PREFIX.$index.self::AGENT_SUFFIX;
    }

    /**
     * Whether an address is one this command hands out.
     *
     * Exact, for the same reason the site key is: a LIKE pattern is read by SQL
     * rather than by this file.
     */
    private static function isSeededAgentAddress(string $email): bool
    {
        // The whole shape, including the INDEX. Checking the affixes alone
        // accepted `desk-agent-owner@example.test`, which this command never
        // creates -- so a real person on an account at the reserved slug could
        // be read as one of ours and deleted with it.
        return preg_match(
            '/^'.preg_quote(self::AGENT_PREFIX, '/').'\d+'.preg_quote(self::AGENT_SUFFIX, '/').'$/',
            $email,
        ) === 1;
    }

    /**
     * Stop if another account already holds a code this run will generate.
     *
     * `conversations.support_code` is globally unique, so a real or legacy row
     * in the range aborts the insert -- and `--fresh` cannot help, because the
     * conflicting row is not this command's to delete.
     *
     * Checked BEFORE anything is built. Discovering it during the conversation
     * pass left the account, its agents, its sites and fifty thousand visitors
     * behind on a run that could never have finished.
     *
     * The RANGE, not every code sharing the prefix: the codes are zero-padded
     * to a fixed width so they sort lexicographically, and `like 'WF-DESK-%'`
     * would also refuse a `WF-DESK-LEGACY-1` that can never collide with one.
     */
    private function refuseCollidingSupportCodes(int $conversations): void
    {
        $ours = Site::query()
            ->whereIn('account_id', Account::query()->where('slug', self::SLUG)->select('id'))
            ->pluck('id')
            ->all();

        // The range narrows the scan; the FORMAT decides. A lexicographic range
        // also contains `WF-DESK-0000003-LEGACY`, which this command will never
        // insert and which therefore cannot collide -- so refusing on the range
        // alone rejects a fixture that would have been fine.
        $collisions = DB::table('conversations')
            ->whereBetween('support_code', [$this->supportCode(0), $this->supportCode($conversations - 1)])
            ->when($ours !== [], fn ($query) => $query->whereNotIn('site_id', $ours))
            ->pluck('support_code')
            ->filter(fn (string $code): bool => preg_match('/^WF-DESK-\d{7}$/', $code) === 1)
            ->take(3)
            ->values();

        if ($collisions->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'Conversations outside the measurement desk already use codes this command generates: '
            .$collisions->implode(', ').'. Rename or remove them; `--fresh` cannot, because they are '
            .'not this command\'s to delete.'
        );
    }

    /**
     * The support code for one seeded conversation.
     *
     * Zero-padded to a fixed width so the codes sort lexicographically, which
     * is what lets the collision check above ask about a RANGE rather than
     * about everything sharing the prefix.
     */
    private function supportCode(int $index): string
    {
        return 'WF-DESK-'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
    }

    /**
     * The index a seeded conversation was written under.
     *
     * Read back out of the support code, because the database id is NOT stable:
     * auto-increment does not restart after `--fresh`, and a desk created beside
     * existing conversations starts higher again. Hashing the id therefore
     * produced a different fixture on every reseed -- different conversations
     * unread, different agents holding read states -- which quietly breaks the
     * reproducibility this whole measurement rests on.
     */
    private function seededIndex(string $supportCode): int
    {
        return (int) mb_substr($supportCode, mb_strlen('WF-DESK-'));
    }

    /**
     * This desk's site ids.
     *
     * Every pass that reads rows back scopes through these. The support-code
     * prefix alone is a naming convention, not a boundary -- and this command
     * promises to touch nothing outside its own account.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     * @return list<int>
     */
    /**
     * Write the lifecycle history the reports are computed from.
     *
     * Conversations and tickets are inserted at their final status rather than
     * driven through the application, so none of the events a real close leaves
     * behind existed -- and `SupportReport` and `TicketReport` read exactly
     * those. Measuring the report tabs against the fixture without this would
     * time a query over an empty table and call the page fast, which is worse
     * than not measuring it.
     *
     * Shaped like `ConversationLifecycleLog::record()` writes them, including
     * `metadata.actor`, because the reopen figures split on it.
     *
     * @param  array{sites: list<Site>, agents: list<User>}  $desk
     */
    private function seedLifecycleHistory(array $desk): int
    {
        $siteIds = $this->siteIds($desk);
        $agentIds = array_map(fn (User $agent): int => $agent->id, $desk['agents']);
        $accountId = $desk['sites'][0]->account_id;
        $written = 0;

        // CLOSED conversations, and the reopens that came before some of them.
        Conversation::query()
            ->whereIn('site_id', $siteIds)
            ->where('support_code', 'like', 'WF-DESK-%')
            ->whereNotNull('closed_at')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($conversations) use (&$written, $accountId, $agentIds): void {
                $rows = [];

                // The messages carried by the conversations that will actually
                // be REOPENED -- a quarter of them, and the mix is deterministic
                // so it can be asked before the loop rather than inside it. A
                // close has to sit in a GAP between two messages: in the product
                // a message on a closed conversation reopens it, so a fixture
                // with messages arriving while it is supposedly closed depicts a
                // state no install can reach, and `ResolutionEpisodes` starts
                // the second episode at the wrong moment because of it.
                //
                // Filtered rather than loading every message in the chunk: at
                // `--conversations=500 --messages=400` that was 200,000 rows in
                // memory at once, which is the peak `seedMessages()` avoids by
                // writing in batches. This asks for a quarter of them, and only
                // the four columns it reads.
                $reopening = $conversations
                    ->filter(fn ($row): bool => self::mix($this->seededIndex((string) $row->support_code), 'reopened', 4) === 0)
                    ->pluck('id');

                $messageTimes = $reopening->isEmpty() ? collect() : DB::table('conversation_messages')
                    ->whereIn('conversation_id', $reopening)
                    ->orderBy('conversation_id')
                    ->orderBy('created_at')
                    ->get(['conversation_id', 'created_at', 'sender_type', 'sender_id'])
                    ->groupBy('conversation_id');

                foreach ($conversations as $conversation) {
                    $n = $this->seededIndex((string) $conversation->support_code);
                    $closedAt = Carbon::parse($conversation->closed_at);

                    // A quarter were CLOSED, reopened, and closed again. The
                    // earlier close is the point: `ResolutionEpisodes::walk()`
                    // starts every conversation in OPEN and ignores a reopen
                    // from OPEN, so a reopen with nothing before it inflated the
                    // raw counter without starting a second episode -- and the
                    // final close was still measured from the original opening.
                    // The ticket half had this right and the conversation half
                    // did not.
                    //
                    // A third of the reopens are by the VISITOR, because the
                    // report splits on exactly that, so a fixture where every
                    // reopen is an agent leaves half the figure unexercised.
                    if (self::mix($n, 'reopened', 4) === 0) {
                        $openedAt = Carbon::parse($conversation->created_at);
                        $messages = ($messageTimes[$conversation->id] ?? collect())->values();

                        // The reopen sits ON a message, and the close just
                        // before it -- which is exactly how the product gets
                        // there: somebody writes to a closed conversation and
                        // that reopens it. Nothing then falls between them.
                        //
                        // The middle message, so both episodes carry some of
                        // the traffic. With too few messages to split, the
                        // conversation is left with its single close rather
                        // than inventing a gap that its own messages contradict.
                        // A pivot whose SENDER is the one this conversation is
                        // meant to be reopened by, searching outward from the
                        // middle. Taking the middle message unconditionally
                        // collapsed the split at `--messages=2`: counts are 1-3
                        // there, and both 2 and 3 pick index 1, which is always
                        // an agent because messages alternate from the visitor.
                        // Every reopen came out agent-driven and
                        // `reopened_by_visitor` read zero -- the figure this
                        // history exists to exercise.
                        $wantVisitor = self::mix($n, 'reopened_by', 3) === 0;
                        $pivot = self::pivotFor($messages, $wantVisitor);

                        if ($pivot === null) {
                            $rows[] = $this->closeRow($conversation, $accountId, $agentIds, $n, $closedAt);

                            continue;
                        }

                        $pivotMessage = $messages[$pivot];
                        $reopenedAt = Carbon::parse($pivotMessage->created_at);
                        $previous = Carbon::parse($messages[$pivot - 1]->created_at);

                        // Halfway through the gap, so it is strictly after the
                        // message before and strictly before the one that
                        // reopens it.
                        $gap = max(2, (int) $previous->diffInSeconds($reopenedAt));
                        $firstCloseAt = $previous->copy()->addSeconds(intdiv($gap, 2));

                        if ($firstCloseAt->greaterThanOrEqualTo($reopenedAt) || $firstCloseAt->lessThanOrEqualTo($openedAt)) {
                            $rows[] = $this->closeRow($conversation, $accountId, $agentIds, $n, $closedAt);

                            continue;
                        }

                        // WHOSE message it was. The reopen is caused by that
                        // message, so attributing it to anyone else describes
                        // history no install can produce -- and both
                        // `reopened_by_visitor` and the actor activity table
                        // are computed from this. It was an independent mix
                        // before, which disagreed with the sender about a
                        // quarter of the time.
                        $byVisitor = $pivotMessage->sender_type === (new Visitor)->getMorphClass();

                        $rows[] = $this->closeRow($conversation, $accountId, $agentIds, $n, $firstCloseAt);

                        $rows[] = [
                            'account_id' => $accountId,
                            'site_id' => $conversation->site_id,
                            'actor_type' => $pivotMessage->sender_type,
                            'actor_id' => $pivotMessage->sender_id,
                            'subject_type' => (new Conversation)->getMorphClass(),
                            'subject_id' => $conversation->id,
                            'action' => ConversationLifecycleLog::REOPENED,
                            'metadata' => json_encode([
                                'previous_status' => 'closed',
                                'actor' => $byVisitor ? 'visitor' : 'agent',
                            ]),
                            'occurred_at' => $reopenedAt,
                            'created_at' => $reopenedAt,
                            'updated_at' => $reopenedAt,
                        ];
                    }

                    $rows[] = $this->closeRow($conversation, $accountId, $agentIds, $n, $closedAt);
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('audit_events')->insert($chunk);
                    $written += count($chunk);
                }
            });

        $written += $this->seedTicketLifecycle($desk, $accountId, $agentIds);

        return $written;
    }

    /**
     * Ticket lifecycle, which `TicketReport` walks separately.
     *
     * Its own actions and its own walk: seeding the conversation half gives the
     * ticket half nothing. It also reads an operator setting to know how far
     * back its history can be trusted, so that is written too -- without it the
     * report measures against a window the data does not cover.
     *
     * @param  list<int>  $agentIds
     */
    private function seedTicketLifecycle(array $desk, ?int $accountId, array $agentIds): int
    {
        $written = 0;

        Ticket::query()
            ->with('conversation:id,support_code')
            ->whereIn('site_id', $this->siteIds($desk))
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($tickets) use (&$written, $accountId, $agentIds): void {
                $rows = [];

                // The AGENT messages on these tickets' conversations. A ticket
                // reply is not an event on its own: `storeReply()` writes a
                // conversation message and puts the conversation back to open.
                // Inventing reply events at arbitrary moments claimed replies
                // with no transcript behind them, usually after the
                // conversation had closed and with no later close -- so the
                // ticket activity figures contradicted the conversation ones.
                //
                // Placed ON existing agent messages instead. Nothing new is
                // written, the timestamps are inside the conversation's life by
                // construction, and the reply the figure counts is one an agent
                // actually sent.
                // At most `self::MAX_TICKET_REPLIES` per conversation, chosen
                // in SQL rather than by loading everything and taking the first
                // few: at `--conversations=5000 --messages=400` a 500-ticket
                // chunk pulled about 100,000 message rows into memory to use
                // three of them, which is the same peak the conversation pass
                // was fixed for one round ago.
                $conversationIds = $tickets->pluck('conversation_id')->filter();

                $ranked = DB::table('conversation_messages')
                    ->select(['conversation_id', 'created_at', 'sender_id'])
                    ->selectRaw('row_number() over (partition by conversation_id order by created_at, id) as rn')
                    ->whereIn('conversation_id', $conversationIds)
                    ->where('sender_type', (new User)->getMorphClass());

                $agentMessages = $conversationIds->isEmpty() ? collect() : DB::query()
                    ->fromSub($ranked, 'ranked')
                    ->where('rn', '<=', self::MAX_TICKET_REPLIES)
                    ->orderBy('conversation_id')
                    ->orderBy('rn')
                    ->get()
                    ->groupBy('conversation_id');

                foreach ($tickets as $ticket) {
                    $raisedAt = Carbon::parse($ticket->created_at);

                    // The conversation's SEEDED INDEX, not the ticket's id.
                    // `--fresh` does not reset a PostgreSQL sequence, so ids
                    // move on every reseed -- which would change which tickets
                    // get a reopen episode and which agent acted, and make two
                    // runs of the same command incomparable. Every other shape
                    // in this file is keyed the same way for the same reason.
                    $n = $this->seededIndex((string) $ticket->conversation->support_code);
                    $actorId = $agentIds[self::mix($n, 'ticket_actor', count($agentIds))];

                    $event = function (string $action, Carbon $at, string $previous) use (&$rows, $accountId, $ticket, $actorId): void {
                        $rows[] = [
                            'account_id' => $accountId,
                            'site_id' => $ticket->site_id,
                            'actor_type' => (new User)->getMorphClass(),
                            'actor_id' => $actorId,
                            'subject_type' => (new Ticket)->getMorphClass(),
                            'subject_id' => $ticket->id,
                            'action' => $action,
                            'metadata' => json_encode(['previous_status' => $previous, 'actor' => 'agent']),
                            'occurred_at' => $at,
                            'created_at' => $at,
                            'updated_at' => $at,
                        ];
                    };

                    // Replies, which `TicketReport::agentActivity()` counts on
                    // their own action. Without them every agent read zero and
                    // the aggregation measured an empty result at every desk
                    // size -- the same shape as ratings that carried no comment.
                    //
                    // One per agent message, up to three, and credited to the
                    // agent who sent it rather than to a mixed-in one.
                    $onConversation = $agentMessages[$ticket->conversation_id] ?? collect();
                    $take = min($onConversation->count(), self::mix($n, 'ticket_replies', self::MAX_TICKET_REPLIES) + 1);

                    for ($r = 0; $r < $take; $r++) {
                        $message = $onConversation[$r];

                        $rows[] = [
                            'account_id' => $accountId,
                            'site_id' => $ticket->site_id,
                            'actor_type' => (new User)->getMorphClass(),
                            'actor_id' => $message->sender_id,
                            'subject_type' => (new Ticket)->getMorphClass(),
                            'subject_id' => $ticket->id,
                            'action' => 'ticket.reply_sent',
                            'metadata' => json_encode(['previous_status' => 'open', 'actor' => 'agent']),
                            'occurred_at' => $message->created_at,
                            'created_at' => $message->created_at,
                            'updated_at' => $message->created_at,
                        ];
                    }

                    // A held ticket has been put on hold, whatever it did next.
                    if ($ticket->status === 'pending') {
                        $event('ticket.pending', $raisedAt->copy()->addHours(2)->min(Carbon::now()), 'open');
                    }

                    if ($ticket->closed_at !== null) {
                        $closedAt = Carbon::parse($ticket->closed_at);

                        // A fifth were closed, reopened and closed again, so the
                        // walk sees a ticket contributing more than one
                        // resolution rather than one long one.
                        if (self::mix($n, 'ticket_reopened', 5) === 0) {
                            $firstClose = $raisedAt->copy()->addHours(6)->min($closedAt);
                            $event('ticket.closed', $firstClose, 'open');
                            $event('ticket.reopened', $firstClose->copy()->addHours(2)->min($closedAt), 'closed');
                        }

                        $event('ticket.closed', $closedAt, 'open');
                    }
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('audit_events')->insert($chunk);
                    $written += count($chunk);
                }
            });

        // `reporting.ticket_lifecycle_recording_began_at` is deliberately NOT
        // written. It is installation-wide, so setting it here would move a
        // reporting fact belonging to every real account on the install --
        // backwards, on an upgraded install whose genuine boundary is recent,
        // which would tell those accounts' reports to trust unaudited ticket
        // history. This command writes inside its own account and nowhere else.
        //
        // Nothing is lost by leaving it: a null boundary means the history
        // always was trustworthy, which is exactly true of a desk whose entire
        // history this command wrote. Writing it was solving a problem that
        // does not exist, at the cost of one that does.
        return $written;
    }

    /**
     * Satisfaction answers, which hang off the close they answered about.
     *
     * `conversation_ratings.episode_event_id` points at the audit event that
     * closed the episode, so these can only be written after the lifecycle
     * history exists -- and the report counts them by `episode_closed_at`
     * rather than by when the answer arrived, so both have to agree.
     *
     * @param  array{sites: list<Site>}  $desk
     */
    private function seedRatings(array $desk): int
    {
        $written = 0;
        $scores = ConversationRating::SCORES;

        // The conversation's SUPPORT CODE comes along, because every choice
        // below keys on it rather than on `subject_id`: `--fresh` does not reset
        // a PostgreSQL sequence, so ids move between otherwise identical runs
        // and the satisfaction figures would move with them. Third place in
        // this file where a surrogate id looked like a stable key.
        DB::table('audit_events')
            ->join('conversations', 'conversations.id', '=', 'audit_events.subject_id')
            ->whereIn('audit_events.site_id', $this->siteIds($desk))
            ->where('audit_events.action', ConversationLifecycleLog::CLOSED)
            ->where('audit_events.subject_type', (new Conversation)->getMorphClass())
            ->orderBy('audit_events.id')
            ->select([
                'audit_events.id',
                'audit_events.site_id',
                'audit_events.subject_id',
                'audit_events.occurred_at',
                'conversations.support_code',
            ])
            // When this conversation was next reopened, if it was. A rating
            // belongs to the episode it answered, and `ConversationRatingController`
            // rejects a stale episode token -- so an answer arriving after the
            // reopen is a row the product cannot produce.
            ->selectSub(
                DB::table('audit_events as reopens')
                    ->selectRaw('min(reopens.occurred_at)')
                    ->whereColumn('reopens.subject_id', 'audit_events.subject_id')
                    ->where('reopens.subject_type', (new Conversation)->getMorphClass())
                    ->where('reopens.action', ConversationLifecycleLog::REOPENED)
                    ->whereColumn('reopens.occurred_at', '>', 'audit_events.occurred_at'),
                'next_reopen_at'
            )
            // The cursor column is QUALIFIED, and aliased back to `id`: joined
            // to `conversations`, `chunkById`'s own unqualified `id` predicate
            // is ambiguous and PostgreSQL refuses it.
            ->chunkById(self::CHUNK, function ($events) use (&$written, $scores): void {
                $rows = [];

                foreach ($events as $event) {
                    $n = $this->seededIndex((string) $event->support_code);

                    // Half of closes are answered. A fixture where every close
                    // has an answer makes the "answered" ratio meaningless, and
                    // it is one of the figures the tab exists to show.
                    if (self::mix($n, 'rated', 2) !== 0) {
                        continue;
                    }

                    $closedAt = Carbon::parse($event->occurred_at);
                    $reopenedAt = $event->next_reopen_at !== null
                        ? Carbon::parse($event->next_reopen_at)
                        : null;

                    $answeredAt = $closedAt->copy()
                        ->addMinutes(self::mix($n, 'rated_after', 90) + 1)
                        ->min(Carbon::now());

                    // An episode reopened before the visitor got round to
                    // answering is simply left unanswered, rather than clamped
                    // into a gap that may not exist: with a close and a reopen
                    // minutes apart there is no honest time to put it.
                    if ($reopenedAt !== null && $answeredAt->greaterThanOrEqualTo($reopenedAt)) {
                        continue;
                    }

                    $rows[] = [
                        'conversation_id' => $event->subject_id,
                        'site_id' => $event->site_id,
                        // Weighted toward good, because a desk where a third of
                        // answers are "bad" is not a desk anyone recognises.
                        'score' => $scores[self::mix($n, 'score', 6) < 4 ? 0 : (self::mix($n, 'score2', 2) === 0 ? 1 : 2)],
                        // A comment on a third of answers, because
                        // `SupportReport::comments()` filters on
                        // `whereNotNull` -- with every comment null the section
                        // returned an empty list and the report tab's comment
                        // rows were never rendered at any desk size.
                        'comment' => self::mix($n, 'commented', 3) === 0
                            ? self::RATING_COMMENTS[self::mix($n, 'comment_text', count(self::RATING_COMMENTS))]
                            : null,
                        'rated_at' => $answeredAt,
                        'episode_closed_at' => $closedAt,
                        'episode_event_id' => $event->id,
                        'created_at' => $closedAt,
                        'updated_at' => $closedAt,
                    ];
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('conversation_ratings')->insert($chunk);
                    $written += count($chunk);
                }
            }, 'audit_events.id', 'id');

        return $written;
    }

    /**
     * Say when this install's recording boundary will hide the seeded history.
     *
     * Both boundaries are INSTALLATION-WIDE and belong to every account, so
     * this command will not move them -- doing so would tell real accounts'
     * reports to trust unaudited history. On a clean measurement install they
     * are absent, which means "always trustworthy" and the desk measures whole.
     *
     * On an UPGRADED install they are set and recent, and a desk backdated
     * twelve months mostly predates them: the resolution durations read
     * unmeasurable and the report marks itself partial. That is the report
     * being honest rather than broken, but it is not what somebody measuring
     * report performance is expecting to see, so it is said out loud.
     *
     * Making the desk measurable there would need an account-scoped boundary,
     * which is a change to the reporting model rather than to a fixture.
     */
    private function warnAboutRecordingBoundaries(int $months): void
    {
        // Compared against the LONGEST report anyone can select, not against
        // the twelve months this desk covers. `historyIsPartial()` measures the
        // boundary against the chosen window, and the choices stop at 90 days
        // -- so a boundary six months back sits inside the seeded span and
        // still leaves every available report complete. Warning on the span
        // would have turned into a permanent false positive as installs age,
        // which is how a warning teaches people to ignore it.
        // The window's OWN start, not `now()` minus its length. A 90-day
        // window covers today plus the preceding 89 and begins at the reader
        // day's midnight, so subtracting 90 days lands up to a day earlier and
        // warned about boundaries every available report treats as complete.
        // Recomputing what the thing you are comparing against already knows is
        // how the two drift.
        $longestReport = ReportingWindow::ofDays(max(ReportingWindow::CHOICES))->start;

        $boundaries = OperatorSetting::query()
            ->whereIn('key', [
                'reporting.lifecycle_recording_began_at',
                'reporting.ticket_lifecycle_recording_began_at',
            ])
            ->pluck('value', 'key')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->filter(fn (string $value): bool => Carbon::parse($value)->greaterThan($longestReport));

        if ($boundaries->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            'This install records lifecycle history only from '
            .$boundaries->map(fn (string $value): string => Carbon::parse($value)->toDateString())->implode(' and ')
            .', which is inside the longest report window anyone can select ('.max(ReportingWindow::CHOICES)
            .' days). The report tabs will mark themselves partial and report resolution durations as '
            .'unmeasurable for anything older, even though this desk covers '.$months.' months. '
            .'Those settings belong to every account on this install, so this command will not move them. '
            .'A measurement install with no history recorded before the desk existed reports it whole.'
        );
    }

    /**
     * One close event, so the two paths through the reopen branch cannot
     * disagree about what a close looks like.
     *
     * @param  list<int>  $agentIds
     * @return array<string, mixed>
     */
    /**
     * The index of a message to reopen on, preferring one sent by the kind of
     * actor this conversation is meant to be reopened by.
     *
     * Never index 0: the close has to sit in the gap BEFORE the pivot, and
     * there is no gap before the first message. Falls back to any valid pivot
     * when the preferred sender has none, and to null when nothing qualifies --
     * a conversation with too few messages keeps its single close rather than
     * being given a gap its own transcript contradicts.
     *
     * @param  Collection<int, object>  $messages
     */
    private static function pivotFor($messages, bool $wantVisitor): ?int
    {
        $visitor = (new Visitor)->getMorphClass();
        $middle = intdiv($messages->count(), 2);
        $preferred = null;
        $any = null;

        // Outward from the middle, so both episodes carry some of the traffic
        // and the choice stays deterministic.
        for ($step = 0; $step < $messages->count(); $step++) {
            foreach ([$middle + $step, $middle - $step] as $index) {
                if ($index < 1 || $index >= $messages->count()) {
                    continue;
                }

                $any ??= $index;

                if ($preferred === null && (($messages[$index]->sender_type === $visitor) === $wantVisitor)) {
                    $preferred = $index;
                }
            }
        }

        return $preferred ?? $any;
    }

    private function closeRow(object $conversation, ?int $accountId, array $agentIds, int $n, Carbon $at): array
    {
        return [
            'account_id' => $accountId,
            'site_id' => $conversation->site_id,
            'actor_type' => (new User)->getMorphClass(),
            'actor_id' => $agentIds[self::mix($n, 'agent', count($agentIds))],
            'subject_type' => (new Conversation)->getMorphClass(),
            'subject_id' => $conversation->id,
            'action' => ConversationLifecycleLog::CLOSED,
            'metadata' => json_encode(['previous_status' => 'open', 'actor' => 'agent']),
            'occurred_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    private function siteIds(array $desk): array
    {
        return array_map(fn (Site $site): int => $site->id, $desk['sites']);
    }

    /**
     * Point `last_message_at` at the last message that exists, and keep the
     * closure after it.
     *
     * Written provisionally as "thirty minutes after it opened", which is wrong
     * twice: it claims activity that `--messages=0` never created, and for a
     * recent conversation it can sit in the FUTURE. The queue, the reports and
     * the read states seeded next all read it.
     *
     * One statement, after the messages exist. A conversation with none gets
     * null, which is what the subquery returns for it.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     */
    /**
     * Move a visitor's last sighting up to their newest message.
     *
     * `last_seen_at` means the latest contact by ANY channel, and the visitor
     * directory orders by it. Visitors are written before their conversations
     * exist, so the value was fixed before the messages that follow it: a
     * visitor was shown as last seen minutes before a message they went on to
     * send.
     *
     * `last_web_seen_at` is deliberately left alone. That one means a WEBSITE
     * sighting, and a message is not one -- the live board's presence lanes are
     * computed from it, so moving it would report visitors as on-site because
     * they once wrote in.
     *
     * Compared in the WHERE rather than with `greatest()`, which PostgreSQL has
     * and SQLite does not. The suite runs on both.
     */
    private function syncVisitorLastSeenAt(array $desk): void
    {
        // Resolved in PHP rather than as a correlated subquery in the SET
        // clause: bindings do not reach a `DB::raw()` there, and writing it as
        // one statement means one dialect. This runs once per seed.
        $newest = DB::table('conversation_messages')
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->join('visitors', 'visitors.id', '=', 'conversations.visitor_id')
            ->whereIn('visitors.site_id', $this->siteIds($desk))
            ->where('conversations.support_code', 'like', 'WF-DESK-%')
            ->where('conversation_messages.sender_type', (new Visitor)->getMorphClass())
            ->groupBy('conversations.visitor_id')
            ->select('conversations.visitor_id')
            ->selectRaw('max(conversation_messages.created_at) as newest')
            ->pluck('newest', 'conversations.visitor_id');

        if ($newest->isEmpty()) {
            return;
        }

        // Grouped by TIMESTAMP so this is one statement per distinct value
        // rather than one per visitor. The comparison stays in the database, so
        // a visitor already seen later than their newest message keeps the
        // later sighting.
        $byTimestamp = [];

        foreach ($newest as $visitorId => $at) {
            $byTimestamp[(string) $at][] = (int) $visitorId;
        }

        foreach ($byTimestamp as $at => $visitorIds) {
            foreach (array_chunk($visitorIds, self::CHUNK) as $chunk) {
                DB::table('visitors')
                    ->whereIn('id', $chunk)
                    ->where('last_seen_at', '<', $at)
                    ->update(['last_seen_at' => $at]);
            }
        }
    }

    private function syncLastMessageAt(array $desk): void
    {
        DB::table('conversations')
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('support_code', 'like', 'WF-DESK-%')
            ->update([
                'last_message_at' => DB::raw(
                    '(select max(created_at) from conversation_messages'
                    .' where conversation_messages.conversation_id = conversations.id)'
                ),
            ]);

        // A conversation cannot close before its last message. The closure was
        // a fixed four hours after opening, and messages are a minute apart --
        // so `--messages=500`, which is exactly what somebody measuring a long
        // conversation detail would reach for, put most of them after it.
        DB::table('conversations')
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('support_code', 'like', 'WF-DESK-%')
            ->whereNotNull('closed_at')
            ->update([
                'closed_at' => DB::raw(
                    'case when last_message_at is not null and last_message_at > closed_at'
                    .' then last_message_at else closed_at end'
                ),
            ]);
    }

    /**
     * Which conversations each agent has already read.
     *
     * Without these every seeded conversation reads as NEW activity, because
     * `Conversation::scopeWithNewActivityFor()` treats a missing row as unread.
     * The queue's new-activity marker was therefore on for every row and its
     * absence never rendered -- a distinction the fixture is supposed to make
     * measurable.
     *
     * Two thirds of the rows get one: half of those read AFTER the last message
     * (nothing new) and half before it (new activity on a conversation the
     * agent has seen at least once), which are different states from never
     * having opened it at all.
     *
     * @param  array{account: Account, sites: list<Site>, agents: list<User>}  $desk
     */
    private function seedReadStates(array $desk): int
    {
        $agentIds = array_map(fn (User $agent): int => $agent->id, $desk['agents']);
        $written = 0;

        DB::table('conversations')
            ->whereIn('site_id', $this->siteIds($desk))
            ->where('support_code', 'like', 'WF-DESK-%')
            ->orderBy('id')
            ->select(['id', 'last_message_at', 'created_at', 'support_code'])
            ->chunk(self::CHUNK, function ($conversations) use ($agentIds, &$written): void {
                $rows = [];

                foreach ($conversations as $conversation) {
                    $index = $this->seededIndex((string) $conversation->support_code);
                    $state = self::mix($index, 'read', 3);

                    if ($state === 2) {
                        continue;
                    }

                    // `Carbon::parse(null)` is NOW, so with `--messages=0` the
                    // read positions were anchored to the moment of seeding
                    // rather than to the conversation's own activity boundary.
                    $lastMessageAt = Carbon::parse($conversation->last_message_at ?? $conversation->created_at);

                    $openedAt = Carbon::parse($conversation->created_at);

                    // Never before the conversation existed. An hour before the
                    // last message is an hour before the conversation opened on
                    // anything short -- and with `--messages=0` it is exactly
                    // an hour before it was created, so an agent had read a
                    // conversation that did not yet exist.
                    $lastReadAt = $state === 0
                        ? $lastMessageAt->copy()->addMinute()
                        : $lastMessageAt->copy()->subHour()->max($openedAt);

                    // The FIRST agent always, because that is the one the
                    // measurement signs in as and the queue evaluates read
                    // states only for the current agent. Spreading them across
                    // eight hashed agents gave the measured one an eighth of
                    // the rows, so the marker it renders was almost always the
                    // never-opened state after all.
                    $rows[] = [
                        'conversation_id' => $conversation->id,
                        'user_id' => $agentIds[0],
                        'last_read_at' => $lastReadAt,
                        'created_at' => $lastMessageAt,
                        'updated_at' => $lastMessageAt,
                    ];

                    // And a colleague on some of them, so the table is not
                    // single-agent in a way no real desk is.
                    $colleague = self::mix($index, 'reader', count($agentIds));

                    if ($colleague !== 0) {
                        $rows[] = [
                            'conversation_id' => $conversation->id,
                            'user_id' => $agentIds[$colleague],
                            // Clamped like the first one. Five minutes earlier
                            // than a position already pinned to the opening is
                            // five minutes before the conversation existed.
                            'last_read_at' => $lastReadAt->copy()->subMinutes(5)->max($openedAt),
                            'created_at' => $lastMessageAt,
                            'updated_at' => $lastMessageAt,
                        ];
                    }
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('conversation_read_states')->insert($chunk);
                    $written += count($chunk);
                }
            });

        return $written;
    }

    /**
     * Subjects with enough spread that a search is a search.
     *
     * Twelve openings rather than one repeated: a `LIKE` across fifty thousand
     * identical subjects either matches everything or nothing, and neither
     * timing tells an operator anything about their own desk.
     *
     * @return list<string>
     */
    private function subjects(): array
    {
        return [
            'Refund not received for order',
            'Cannot sign in after password reset',
            'Invoice shows the wrong VAT rate',
            'Widget not loading on checkout',
            'Duplicate charge on card ending',
            'Export finished but the file is empty',
            'Two-factor codes rejected',
            'Delivery address will not save',
            'Subscription renewed after cancelling',
            'Attachment upload fails over 5MB',
            'Report totals disagree with the dashboard',
            'Account owner left the company',
        ];
    }

    /**
     * Run one step, printing what it wrote and how long it took.
     *
     * @param  callable(): int  $step
     */
    private function measure(string $label, callable $step): int
    {
        $startedAt = microtime(true);
        $written = $step();

        $this->components->twoColumnDetail(
            $label,
            ReaderNumber::count((int) $written).' rows <fg=gray>'.ReaderNumber::decimal(microtime(true) - $startedAt, 1).'s</>',
        );

        return $written;
    }
}
