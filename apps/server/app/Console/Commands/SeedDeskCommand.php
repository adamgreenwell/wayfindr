<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\ReaderNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

            $written['visitors'] = $this->measure('Visitors', fn (): int => $this->seedVisitors($desk, $conversations, $months));
            $written['conversations'] = $this->measure('Conversations', fn (): int => $this->seedConversations($desk, $conversations, $months));
            $written['messages'] = $this->measure('Messages', fn (): int => $this->seedMessages($desk, $messagesEach));
            $written['tickets'] = $this->measure('Tickets', fn (): int => $this->seedTickets($desk));
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
            ['name' => 'Measurement Desk'],
        );

        // `users.email` is globally unique, so `updateOrCreate` keyed on the
        // address alone does not create a second user -- it MOVES the existing
        // one onto this account. A real person holding a `desk-agent-` address
        // elsewhere would have been quietly reassigned to a seeded desk whose
        // password this command prints.
        //
        // Refused instead. Failing is the correct answer to "somebody already
        // holds the address I need": it is recoverable, and taking over their
        // account is not.
        $taken = User::query()
            ->where('email', 'like', 'desk-agent-%@example.test')
            ->where(fn ($query) => $query->whereNull('account_id')->orWhere('account_id', '!=', $account->id))
            ->pluck('email');

        if ($taken->isNotEmpty()) {
            throw new RuntimeException(
                'These addresses belong to a user outside the measurement desk, and this command '
                .'would take them over: '.$taken->implode(', ').'. Move or remove them first.'
            );
        }

        $agents = [];

        for ($i = 0; $i < $agentCount; $i++) {
            $agents[] = User::query()->updateOrCreate(
                ['email' => 'desk-agent-'.$i.'@example.test', 'account_id' => $account->id],
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
    private function seedVisitors(array $desk, int $conversations, int $months): int
    {
        $now = Carbon::now();
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
                'last_seen_at' => $seenAt,
                'created_at' => $seenAt,
                'updated_at' => $seenAt,
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
    private function seedConversations(array $desk, int $conversations, int $months): int
    {
        $now = Carbon::now();
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
                'support_code' => 'WF-DESK-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'status' => $open ? 'open' : 'closed',
                'subject' => $subjects[$i % count($subjects)].' '.$i,
                'metadata' => json_encode([]),
                'last_message_at' => $openedAt->copy()->addMinutes(30),
                'closed_at' => $open ? null : $openedAt->copy()->addHours(4),
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
            ->select(['id', 'visitor_id', 'created_at'])
            ->chunk(self::CHUNK, function ($conversations) use ($messagesEach, $agentIds, &$written): void {
                $rows = [];

                foreach ($conversations as $index => $conversation) {
                    $startedAt = Carbon::parse($conversation->created_at);

                    // Varied, not uniform. Every conversation holding exactly
                    // six messages makes the detail page's cost a constant, and
                    // the long ones are where it is worth knowing.
                    $count = max(1, $messagesEach + ($index % 5) - 2);

                    // Roughly a third, independent of everything else.
                    $unread = self::mix((int) $conversation->id, 'unread', 3) === 0;

                    for ($m = 0; $m < $count; $m++) {
                        $fromVisitor = $m % 2 === 0;

                        $rows[] = [
                            'conversation_id' => $conversation->id,
                            'sender_type' => $fromVisitor ? Visitor::class : User::class,
                            'sender_id' => $fromVisitor ? $conversation->visitor_id : $agentIds[$m % count($agentIds)],
                            'type' => 'text',
                            'body' => 'Message '.$m.' on conversation '.$conversation->id.'. '
                                .'Enough words that a body column holds something worth reading past.',
                            'metadata' => json_encode([]),
                            // The LAST visitor message on an open conversation is
                            // unread, which is what a desk that has not caught up
                            // looks like -- and what the queue's attention lanes
                            // are computed from. Marking every message seen left
                            // those branches unrendered and made the detail page's
                            // read-marking a no-op, so nothing measured it.
                            'seen_at' => $unread && $fromVisitor && $m === $count - 1
                                ? null
                                : $startedAt->copy()->addMinutes($m + 1),
                            'created_at' => $startedAt->copy()->addMinutes($m),
                            'updated_at' => $startedAt->copy()->addMinutes($m),
                        ];
                    }
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('conversation_messages')->insert($chunk);
                    $written += count($chunk);
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
            ->select(['id', 'site_id', 'visitor_id', 'created_at', 'subject'])
            ->chunk(self::CHUNK, function ($conversations) use ($desk, $agentIds, $categories, $priorities, $statuses, &$written): void {
                $rows = [];

                foreach ($conversations as $index => $conversation) {
                    if ($index % 4 !== 0) {
                        continue;
                    }

                    // Salted per attribute rather than cycled on one counter --
                    // see `mix()` for the three ways the counter went wrong.
                    $n = $written + count($rows);

                    $raisedAt = Carbon::parse($conversation->created_at);
                    $status = $statuses[self::mix($n, 'status', count($statuses))];

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
                        'description' => 'Raised from conversation '.$conversation->id.'.',
                        'metadata' => json_encode([]),
                        'closed_at' => $status === 'closed' ? $raisedAt->copy()->addDays(2) : null,
                        'created_at' => $raisedAt,
                        'updated_at' => $raisedAt,
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

        if ($foreignSites === 0 && $foreignUsers === 0) {
            return;
        }

        throw new RuntimeException(
            'The account at `'.self::SLUG.'` holds '.$foreignSites.' site(s) and '.$foreignUsers
            .' user(s) this command did not create, so it is somebody\'s real desk rather than a '
            .'previous measurement. Refusing to delete it. Rename that account if you want the slug.'
        );
    }

    /**
     * Whether an address is one this command hands out.
     *
     * Exact, for the same reason the site key is: a LIKE pattern is read by SQL
     * rather than by this file.
     */
    private static function isSeededAgentAddress(string $email): bool
    {
        return str_starts_with($email, self::AGENT_PREFIX) && str_ends_with($email, self::AGENT_SUFFIX);
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
    private function siteIds(array $desk): array
    {
        return array_map(fn (Site $site): int => $site->id, $desk['sites']);
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
