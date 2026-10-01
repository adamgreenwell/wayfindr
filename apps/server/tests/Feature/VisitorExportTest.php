<?php

// Exporting a contact (ADR 0026 §7). The export is the read side of the
// erasure map: the same fixture that plants a marker in every store erasure
// must reach is exported here, and the archive must hold every one of those
// markers and nothing of the bystander's. Its rows are then held column by
// column to the live schema, so a column added later is left out, and fails
// here, until someone decides what the export does with it.

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\AutomationMacro;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationReplyDelivery;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Visitors\VisitorEraser;
use App\Support\Visitors\VisitorExporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisitorHistory;

uses(RefreshDatabase::class);

/**
 * The erasure fixture, plus the stores it has no row in that the export
 * reads: reply deliveries, copilot output, break-glass access, and agent
 * identities written the ways the code writes them.
 *
 * @return array<string, mixed>
 */
function exportFixture(): array
{
    $f = VisitorHistory::create();
    $m = VisitorHistory::MARKER;
    // An ID nothing else in the fixture can have, so an agent's ID left in
    // the archive is found by searching for it.
    static $fixtures = 0;
    $fixtures++;
    $agent = User::factory()->for($f['account'])->create(['id' => 987660 - $fixtures, 'name' => "Agent Sam Rivers {$fixtures}", 'email' => "sam.rivers.{$fixtures}@agents.test"]);
    $message = ConversationMessage::query()->where('conversation_id', $f['conversation']->id)->firstOrFail();
    // Sent and scanned, as the download path requires before serving it.
    ConversationMessageAttachment::query()->whereKey($f['attachment']->id)->update(['status' => ConversationMessageAttachment::STATUS_READY]);

    ConversationReplyDelivery::query()->forceCreate([
        'conversation_message_id' => $message->id,
        'recipient' => strtolower($m).'@example.test',
        'message_id' => '<'.Str::uuid().'@wayfindr.test>',
    ]);

    foreach ([
        [ConversationCopilotSummary::class, ['summary' => "Summary {$m}"]],
        [ConversationCopilotReplyDraft::class, ['draft' => "Draft {$m}"]],
        [ConversationCopilotTicketSuggestion::class, ['title' => "Title {$m}", 'priority' => 'high', 'label_ids' => []]],
        [ConversationCopilotKnowledgeSuggestion::class, ['article_ids' => []]],
    ] as [$model, $content]) {
        $model::query()->forceCreate([
            'conversation_id' => $f['conversation']->id,
            'requested_by_id' => $agent->id,
            'generation' => (string) Str::uuid(),
            'status' => 'completed',
            'requested_at' => now(),
            ...$content,
        ]);
    }

    // Agent identities, written the ways the code writes them.
    CobrowseSession::query()->where('conversation_id', $f['conversation']->id)->update(['requested_by_id' => $agent->id, 'metadata' => json_encode([
        'page_state' => ['url' => "https://shop.example/cobrowse/{$m}"],
        'resync_request' => ['requested_by_id' => $agent->id, 'requested_by_name' => $agent->name],
        'ended_by_id' => $agent->id, 'ended_by_name' => $agent->name, 'ended_by_type' => 'agent',
    ])]);
    $f['conversation']->forceFill(['assigned_agent_id' => $agent->id])->save();
    $f['ticket']->forceFill(['assignee_id' => $agent->id])->save();
    // Typing, and gone before saying they had stopped: the signal stays.
    test()->actingAs($agent)
        ->postJson(route('dashboard.conversations.typing.store', $f['conversation']->support_code), ['is_typing' => true])
        ->assertOk();
    expect($f['conversation']->refresh()->metadata['agent_typing'] ?? [])->toHaveKey((string) $agent->id);
    ConversationMessage::query()->forceCreate([
        'conversation_id' => $f['conversation']->id, 'sender_type' => $agent->getMorphClass(), 'sender_id' => $agent->id,
        'type' => 'text', 'body' => "Agent reply {$m}",
    ]);
    AuditEvent::query()->create([
        'account_id' => $f['account']->id, 'site_id' => $f['site']->id,
        'actor_type' => $f['admin']->getMorphClass(), 'actor_id' => $f['admin']->id,
        'subject_type' => $f['ticket']->getMorphClass(), 'subject_id' => $f['ticket']->id,
        'action' => 'ticket.assignee_updated',
        'metadata' => ['old_assignee_id' => null, 'old_assignee_name' => null, 'new_assignee_id' => $agent->id, 'new_assignee_name' => $agent->name],
        'occurred_at' => now(),
    ]);
    $macro = AutomationMacro::factory()->create(['account_id' => $f['account']->id]);
    AuditEvent::query()->create([
        'account_id' => $f['account']->id, 'site_id' => $f['site']->id,
        'actor_type' => $f['admin']->getMorphClass(), 'actor_id' => $f['admin']->id,
        'subject_type' => $macro->getMorphClass(), 'subject_id' => $macro->id,
        'action' => 'automation_macro.applied',
        'metadata' => ['support_subject_type' => $f['conversation']->getMorphClass(), 'support_subject_id' => $f['conversation']->id, 'note' => "Macro {$m}"],
        'occurred_at' => now(),
    ]);

    // A job that names their conversation by its support code, unique to the
    // install, as the email-only one the history fixture holds is not.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverOutboundWebhook']),
        'exception' => "RuntimeException: could not deliver {$f['conversation']->support_code} for Robin {$m}\n#0 /app/Jobs/DeliverOutboundWebhook.php(42)",
        'failed_at' => now(),
    ]);

    // A platform operator looked at their conversation and ticket.
    $operator = User::factory()->create(['platform_role' => 'operator', 'name' => 'Operator Pat']);
    $grant = BreakGlassGrant::factory()->activeFor($f['account'], $operator)->create([
        'scope_type' => BreakGlassGrant::SCOPE_CONVERSATION,
        'conversation_id' => $f['conversation']->id,
        'site_id' => $f['site']->id,
        'reason' => "Reason {$m}",
    ]);

    foreach ([['conversation', $f['conversation']->id], ['ticket', $f['ticket']->id]] as [$type, $id]) {
        $grant->auditEvents()->create([
            'account_id' => $f['account']->id, 'site_id' => null,
            'actor_type' => $operator->getMorphClass(), 'actor_id' => $operator->id,
            'action' => 'break_glass.resource_viewed',
            'metadata' => ['scope_type' => $grant->scope_type, 'scope_label' => 'Conversation', 'resource_type' => $type, 'resource_id' => $id, 'resource_label' => "{$type} {$m}", 'requester' => $operator->name],
            'occurred_at' => now(),
        ]);
    }

    return [...$f, 'agent' => $agent, 'operator' => $operator, 'grant' => $grant];
}

/**
 * Export through the dashboard, as the agent would, and read the archive.
 *
 * @return array<string, string> entry name => contents
 */
function exportArchive(User $agent, Visitor $visitor): array
{
    $response = test()->actingAs($agent)->post(route('dashboard.visitors.data-export', $visitor));
    $response->assertOk();

    return readExport($response);
}

/** @return array<string, string> */
function readExport(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue('the export is not a ZIP file');
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink($path);
    ksort($entries);

    return $entries;
}

/**
 * The rows a coverage location names: `file#key`, the file a glob.
 *
 * @param  array<string, string>  $entries
 * @return list<array<string, mixed>>
 */
function exportRowsAt(array $entries, string $location): array
{
    [$glob, $key] = explode('#', $location, 2);
    $rows = [];

    foreach ($entries as $name => $contents) {
        if (! fnmatch($glob, $name, FNM_PATHNAME)) {
            continue;
        }

        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR)[$key] ?? [];
        $rows = [...$rows, ...(array_is_list($value) ? $value : [$value])];
    }

    return $rows;
}

test('an export holds everything held about the person, and nothing about anyone else', function (): void {
    $f = exportFixture();
    $entries = exportArchive($f['admin'], $f['visitor']);
    $code = $f['conversation']->support_code;

    expect(array_keys($entries))->toContain(
        'README.txt', 'visitor.json', "conversations/{$code}.json", "tickets/{$f['ticket']->id}.json",
        'proactive.json', 'alerts.json', 'audit.json', 'incidental.json', 'break_glass.json',
        "attachments/{$f['attachment']->id}-".VisitorHistory::MARKER.'.png',
    );

    // What the fixture planted in each store, found where it belongs. Page
    // addresses are not here: the models store them with the path redacted,
    // and the export gives what is stored.
    $m = VisitorHistory::MARKER;

    foreach ([
        'visitor.json' => [
            'name' => "Robin {$m}", 'external ID' => "host-{$m}", 'browser ID' => "browser-{$m}",
            'alias' => "alias-{$m}", 'attribute' => "\"plan\":\"{$m}\"", 'contact note' => "Note {$m}",
        ],
        "conversations/{$code}.json" => [
            'subject' => "Subject {$m}", 'message' => "Body {$m}", 'agent reply' => "Agent reply {$m}", 'rating' => "Rating {$m}",
            'cobrowse page' => "cobrowse/{$m}", 'reply recipient' => strtolower($m).'@example.test',
            'copilot summary' => "Summary {$m}", 'reply draft' => "Draft {$m}", 'ticket suggestion' => "Title {$m}",
        ],
        "tickets/{$f['ticket']->id}.json" => [
            'ticket subject' => "Ticket {$m}", 'note' => "Ticket note {$m}", 'note delivery' => "Comment {$m}",
        ],
        'alerts.json' => ['message preview' => "Preview {$m}", 'ticket subject' => "Ticket {$m}"],
        'audit.json' => ['conversation event' => "Reason {$m}", 'macro' => "Macro {$m}", 'ticket note' => "Ticket note {$m}"],
        'incidental.json' => [
            'conversation webhook response' => "Robin {$m}", 'ticket webhook response' => "could not sync Ticket {$m}",
            'automation error' => "'Ticket {$m}'", 'saved search' => "robin {$m}",
            'failed job' => "could not deliver {$code} for Robin {$m}",
        ],
        'break_glass.json' => ['reason' => "Reason {$m}", 'viewed conversation' => "conversation {$m}", 'viewed ticket' => "ticket {$m}"],
    ] as $file => $stores) {
        foreach ($stores as $store => $needle) {
            expect(str_contains($entries[$file], $needle))->toBeTrue("{$file} is missing the person's {$store}");
        }
    }

    expect($entries["attachments/{$f['attachment']->id}-".VisitorHistory::MARKER.'.png'])->toBe('binary');

    foreach ($entries as $name => $contents) {
        expect(str_contains($contents, VisitorHistory::KEEPER))->toBeFalse("{$name} holds the bystander's data")
            ->and(str_contains($contents, $f['admin']->name))->toBeFalse("{$name} names the agent")
            ->and(str_contains($contents, $f['admin']->email))->toBeFalse("{$name} holds the agent's email address")
            ->and(str_contains($contents, $f['operator']->name))->toBeFalse("{$name} names the platform operator")
            ->and(str_contains($contents, $f['agent']->name))->toBeFalse("{$name} names the assigned agent")
            ->and(str_contains($contents, (string) $f['agent']->id))->toBeFalse("{$name} holds an agent's ID");
    }

    $conversation = json_decode($entries["conversations/{$code}.json"], true);
    $ticket = json_decode($entries["tickets/{$f['ticket']->id}.json"], true);

    expect($conversation['conversation']['assigned_agent'])->toBe('agent')
        ->and($conversation['cobrowse_sessions'][0]['metadata']['resync_request']['requested_by_name'])->toBe('agent')
        ->and($conversation['cobrowse_sessions'][0]['metadata']['ended_by_name'])->toBe('agent')
        ->and($conversation['attachments'][0]['file'])->toBe("attachments/{$f['attachment']->id}-".VisitorHistory::MARKER.'.png')
        ->and($ticket['ticket']['assignee'])->toBe('agent')
        ->and(collect(json_decode($entries['audit.json'], true)['events'])->pluck('actor')->unique()->sort()->values()->all())->toBe(['agent', 'visitor']);
});

test('a cobrowse session the visitor ended says so, not that an agent did', function (): void {
    $f = exportFixture();
    CobrowseSession::query()->where('conversation_id', $f['conversation']->id)->update(['metadata' => json_encode([
        'ended_by_name' => 'Visitor', 'ended_by_type' => 'visitor',
    ])]);

    $conversation = json_decode(exportArchive($f['admin'], $f['visitor'])["conversations/{$f['conversation']->support_code}.json"], true);

    expect($conversation['cobrowse_sessions'][0]['metadata']['ended_by_name'])->toBe('visitor');
});

test('every exported row carries exactly the columns the export says it does', function (): void {
    $f = exportFixture();
    $entries = exportArchive($f['admin'], $f['visitor']);

    foreach (VisitorEraser::COVERAGE as $table => $entry) {
        if (str_starts_with($entry['export'], 'not exported:')) {
            continue;
        }

        $rows = [];

        foreach (explode(', ', $entry['export']) as $location) {
            $rows = [...$rows, ...exportRowsAt($entries, $location)];
        }

        expect($rows)->not->toBe([], "the fixture exported no {$table} rows, so its columns went unchecked");

        $exported = array_keys(array_filter(VisitorExporter::COLUMNS[$table], fn (true|string $column): bool => $column === true));
        $derived = VisitorExporter::DERIVED[$table] ?? [];

        foreach ($rows as $row) {
            $columns = array_values(array_diff(array_keys($row), $derived));
            sort($columns);
            sort($exported);

            expect($columns)->toBe($exported, "an exported {$table} row does not hold exactly the columns VisitorExporter::COLUMNS exports");
        }
    }
});

test('the export decides about every column of every table it reads', function (): void {
    foreach (VisitorExporter::COLUMNS as $table => $columns) {
        $schema = Schema::getColumnListing($table);
        sort($schema);
        $decided = array_keys($columns);
        sort($decided);

        expect($decided)->toBe($schema, "VisitorExporter::COLUMNS does not say, for each column of {$table}, whether the export includes it");

        foreach ($columns as $column => $decision) {
            expect($decision === true || (is_string($decision) && trim($decision) !== ''))
                ->toBeTrue("{$table}.{$column} is left out of the export without a reason");
        }
    }
});

test('every table erasure reaches says where the export puts it, or why it leaves it out', function (): void {
    foreach (VisitorEraser::COVERAGE as $table => $entry) {
        expect(trim($entry['erasure'] ?? ''))->not->toBe('', "{$table} does not say what erasure does to it");

        if (str_starts_with($entry['export'], 'not exported:')) {
            expect(trim(substr($entry['export'], strlen('not exported:'))))->not->toBe('', "{$table} is left out of the export without a reason");

            continue;
        }

        foreach (explode(', ', $entry['export']) as $location) {
            expect($location)->toMatch('/^[a-z_]+(\/\*)?\.json#[a-z_]+$/', "{$table}'s export location {$location} is not file#key");
        }

        expect(array_key_exists($table, VisitorExporter::COLUMNS))->toBeTrue("{$table} is exported, but VisitorExporter::COLUMNS does not say which of its columns");
    }
});

test('every key the code writes a user into is replaced by a role, or reviewed', function (): void {
    // Identity-shaped keys, written either way the code writes them: in an
    // array literal, or assigned into one. Columns are held to the schema by
    // the test above, so a column here only needs to be one COLUMNS replaces.
    $pattern = "/'((?:[a-z]+_)*(?:assignee|agent|requester|approver|author|operator|issuer|actor|user)(?:_id|_name)|requester|author|[a-z_]*_by(?:_user)?(?:_id|_name)?)'\\s*(?:=>|\\]\\s*=(?!=))/";
    $columns = [
        'assignee_id', 'assigned_agent_id', 'author_id', 'approver_id', 'uploaded_by_id', 'undone_by_user_id',
        'requester_id', 'actor_id',
    ];
    $reviewed = [
        'agent_id' => 'log context, form fields and realtime session payloads: never stored where the export reads',
        'agent_name' => 'the first-run setup form that creates the first agent',
        'triggered_by_id' => 'backup and restore runs, which are about the installation',
        'satisfied_by' => 'upgrade requirements, which are about the installation',
        'created_by_id' => 'API tokens and webhook endpoints, which the export does not read',
        'last_ticket_agent_id' => 'a site\'s routing state, which the export does not read',
        'last_conversation_agent_id' => 'a site\'s routing state, which the export does not read',
        'issuer_id' => 'an account event about an agent\'s own credentials, never about a visitor\'s work',
        'issuer_name' => 'an account event about an agent\'s own credentials, never about a visitor\'s work',
        'granted_by' => 'a role, never a person: who granted cobrowse consent, which is always the visitor',
        'ended_by' => 'a label built for the widget when it asks, never stored',
        'requested_by' => 'what the widget is told about a cobrowse request when it asks, never stored',
        'confirmed_by' => 'operator readiness confirmations, which are about the installation',
        'confirmed_by_id' => 'operator readiness confirmations, which are about the installation',
        'user_id' => 'read states, SLA alert deliveries and OIDC identities, which the export does not read, and the login session',
    ];

    $found = [];
    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php' || $file->getRealPath() === (new ReflectionClass(VisitorExporter::class))->getFileName()) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        $source .= $contents;
        preg_match_all($pattern, $contents, $matches);

        foreach ($matches[1] as $key) {
            $found[$key][] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    $roleColumns = collect(VisitorExporter::COLUMNS)->flatMap(fn (array $decisions): array => array_keys(array_filter($decisions, fn (true|string $decision): bool => is_string($decision) && str_starts_with($decision, 'replaced by a role'))))->unique()->all();

    foreach ($found as $key => $files) {
        $decided = array_key_exists($key, VisitorExporter::IDENTITY_KEYS)
            || array_key_exists($key, $reviewed)
            || (in_array($key, $columns, true) && (in_array($key, $roleColumns, true) || $key === 'requester_id'));

        expect($decided)->toBeTrue("{$key} (".implode(', ', array_unique($files)).') looks like it names a user: add it to VisitorExporter::IDENTITY_KEYS, or review it here');
    }

    // Neither list outlives what it is about, and the pattern still finds
    // the keys it was written for.
    foreach ([...array_keys(VisitorExporter::IDENTITY_KEYS), ...array_keys($reviewed)] as $key) {
        expect(array_key_exists($key, $found))->toBeTrue("{$key} is listed, but the code no longer writes it");
    }

    foreach (collect(VisitorExporter::OMITTED_KEYS)->flatMap(fn (array $columns): array => array_merge(...array_map(array_keys(...), array_values($columns))))->all() as $key) {
        expect(str_contains($source, "'{$key}'"))->toBeTrue("{$key} is left out, but the code no longer writes it");
    }
});

test('an export is only for someone who handles data requests, about a contact they can see', function (): void {
    $f = exportFixture();
    $agent = User::factory()->for($f['account'])->create(['account_role' => AccountRole::Agent]);

    expect($agent->hasAccountPermission(AccountPermission::HandleDataRequests))->toBeFalse();

    $this->actingAs($agent)->post(route('dashboard.visitors.data-export', $f['visitor']))->assertForbidden();

    $outsider = exportFixture();
    $this->actingAs($outsider['admin'])->post(route('dashboard.visitors.data-export', $f['visitor']))->assertNotFound();

    expect(AuditEvent::query()->where('action', 'visitor.exported')->exists())->toBeFalse();
});

test('an export is not something another site can start with a link', function (): void {
    $f = exportFixture();

    $this->actingAs($f['admin'])->get('/dashboard/visitors/'.$f['visitor']->id.'/data-export')->assertMethodNotAllowed();
});

test('an export is recorded with counts only', function (): void {
    $f = exportFixture();
    exportArchive($f['admin'], $f['visitor']);

    $event = AuditEvent::query()->where('action', 'visitor.exported')->sole();

    expect($event->actor_id)->toBe($f['admin']->id)
        ->and($event->subject_id)->toBe($f['visitor']->id)
        ->and($event->metadata['exported']['conversations'])->toBe(1)
        ->and($event->metadata['exported']['messages'])->toBe(2)
        ->and(json_encode($event->metadata))->not->toContain(VisitorHistory::MARKER);
});

test('a file removed before it could be read is listed as pruned, not left out without a word', function (): void {
    $f = exportFixture();
    Storage::disk('attachments')->delete($f['attachment']->storage_key);

    $entries = exportArchive($f['admin'], $f['visitor']);
    $name = "attachments/{$f['attachment']->id}-".VisitorHistory::MARKER.'.png';

    expect(array_key_exists($name, $entries))->toBeFalse()
        ->and($entries['README.txt'])->toContain($name)
        ->and(json_decode($entries["conversations/{$f['conversation']->support_code}.json"], true)['attachments'][0]['file'])->toBeNull();
});

test('a file the scanner holds is listed as withheld, not handed out', function (): void {
    $f = exportFixture();
    ConversationMessageAttachment::query()->whereKey($f['attachment']->id)->update(['status' => ConversationMessageAttachment::STATUS_QUARANTINED]);

    $entries = exportArchive($f['admin'], $f['visitor']);
    $name = "attachments/{$f['attachment']->id}-".VisitorHistory::MARKER.'.png';

    expect(array_key_exists($name, $entries))->toBeFalse('a quarantined file was handed out in the export')
        ->and($entries['README.txt'])->toContain("{$name} (quarantined)")
        ->and(json_decode($entries["conversations/{$f['conversation']->support_code}.json"], true)['attachments'][0])
        ->toMatchArray(['file' => null, 'status' => 'quarantined']);
});

test('a snapshot a concurrent change spoils is taken again', function (): void {
    $f = exportFixture();
    $failed = 0;
    // What PostgreSQL raises at repeatable read when the site row changed
    // while its lock was awaited.
    DB::listen(function (QueryExecuted $query) use (&$failed): void {
        if ($failed === 0 && str_contains($query->sql, 'visitor_identity_aliases')) {
            $failed++;

            throw new QueryException($query->connectionName, $query->sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: could not serialize access due to concurrent update', 40001));
        }
    });

    $entries = exportArchive($f['admin'], $f['visitor']);

    expect($failed)->toBe(1)
        ->and($entries)->toHaveKey('visitor.json');
});

test('a file an agent has not sent yet is not theirs to receive, but their own unsent upload is', function (): void {
    $f = exportFixture();
    $draft = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create([
        'conversation_message_id' => null, 'original_filename' => 'QZDRAFTQZ.png',
        'uploaded_by_type' => $f['agent']->getMorphClass(), 'uploaded_by_id' => $f['agent']->id,
        'status' => ConversationMessageAttachment::STATUS_READY,
    ]);
    Storage::disk('attachments')->put($draft->storage_key, 'QZDRAFTQZ binary');
    // Recorded the moment it was uploaded, as the upload service does, and a
    // rejected upload of the agent's beside one of the visitor's.
    foreach ([
        [$f['agent'], 'attachment.uploaded', ['attachment_id' => $draft->id, 'filename' => 'QZDRAFTQZ.png']],
        [$f['agent'], 'attachment.quarantined', ['filename' => 'QZDRAFTQZ-rejected.exe', 'threat' => 'Eicar']],
        [$f['visitor'], 'attachment.quarantined', ['filename' => 'their-own-rejected.exe', 'threat' => 'Eicar']],
    ] as [$actor, $action, $metadata]) {
        $f['conversation']->auditEvents()->create([
            'account_id' => $f['account']->id, 'site_id' => $f['site']->id,
            'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id,
            'action' => $action, 'metadata' => $metadata, 'occurred_at' => now(),
        ]);
    }
    $own = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create([
        'conversation_message_id' => null, 'original_filename' => 'mine.png', 'status' => ConversationMessageAttachment::STATUS_READY,
    ]);
    Storage::disk('attachments')->put($own->storage_key, 'their own');

    $entries = exportArchive($f['admin'], $f['visitor']);

    foreach ($entries as $name => $contents) {
        expect(str_contains($name.$contents, 'QZDRAFTQZ'))->toBeFalse("{$name} holds a file an agent has not sent");
    }

    $events = json_decode($entries['audit.json'], true)['events'];

    expect($entries["attachments/{$own->id}-mine.png"] ?? null)->toBe('their own')
        ->and($entries['audit.json'])->toContain('their-own-rejected.exe')
        ->and(array_filter($events, fn (mixed $event): bool => ! is_array($event)))->toBe([], 'audit.json lists a left-out event as a null');
});

test('a failed job that names them only by an email address another contact can share is not exported', function (): void {
    $f = exportFixture();
    $entries = exportArchive($f['admin'], $f['visitor']);

    // The history fixture's mail rejection names their address and nothing
    // that is theirs alone. Erasure removes it; the export cannot claim it.
    $this->assertStringNotContainsString('Recipient address rejected', $entries['incidental.json'], 'a failed job tied to them only by a shareable email address was exported');
    expect($entries['incidental.json'])->toContain($f['conversation']->support_code);
});

test('a saved search that cannot be tied to the person is not handed to them', function (): void {
    $f = exportFixture();
    // From before runs recorded what they selected, and it skipped an item:
    // erasure clears its search to be safe, but it may be about anyone.
    DB::table('conversation_bulk_action_runs')->insert([
        'account_id' => $f['account']->id, 'triggered_by_user_id' => $f['admin']->id, 'action' => 'close',
        'item_count' => 2, 'changed_count' => 1, 'item_ids' => null,
        'changes' => json_encode([['conversation_id' => $f['kept']->id, 'before' => ['status' => 'open'], 'after' => ['status' => 'closed']]]),
        'return_query' => json_encode(['conversation_search' => 'QZLEGACYQZ someone else']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $entries = exportArchive($f['admin'], $f['visitor']);

    $this->assertStringNotContainsString('QZLEGACYQZ', $entries['incidental.json'], 'a search that may be about someone else was handed to the person');
    expect($entries['incidental.json'])->toContain('robin '.VisitorHistory::MARKER);
});

test('a contact erased while the export was being read is not exported', function (): void {
    $f = exportFixture();
    $erased = false;
    // The erasure commits after the export's snapshot was taken: the
    // snapshot still holds the person, the database no longer does.
    DB::listen(function (QueryExecuted $query) use ($f, &$erased): void {
        if (! $erased && str_contains($query->sql, 'proactive_message_deliveries')) {
            $erased = true;
            DB::table('visitors')->where('id', $f['visitor']->id)->delete();
        }
    });

    $this->actingAs($f['admin'])
        ->post(route('dashboard.visitors.data-export', $f['visitor']))
        ->assertRedirect(route('dashboard.visitors.index'))
        ->assertSessionHasErrors(['export' => __('visitor_export.errors.gone')]);

    expect($erased)->toBeTrue('the stand-in erasure never ran')
        ->and(AuditEvent::query()->where('action', 'visitor.exported')->exists())->toBeFalse('an export of someone no longer here was recorded as made');
});

test('a history too large for one archive is refused before anything is written', function (): void {
    $f = exportFixture();
    ConversationMessageAttachment::query()->whereKey($f['attachment']->id)->update(['size_bytes' => VisitorExporter::MAX_BYTES + 1]);

    $this->actingAs($f['admin'])
        ->post(route('dashboard.visitors.data-export', $f['visitor']))
        ->assertRedirect(route('dashboard.visitors.show', $f['visitor']))
        ->assertSessionHasErrors(['export' => __('visitor_export.errors.too_large')]);
});

test('the export reads under a shared lock on the site, taken before anything else', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite has no row locks to see; the PostgreSQL job runs this.');
    }

    $f = exportFixture();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    exportArchive($f['admin'], $f['visitor']);

    $queries = collect($queries);
    $firstRead = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "visitor_identity_aliases"') || str_contains($sql, 'from "conversations"'));
    $lock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "sites"') && str_contains($sql, 'for share'));

    expect($lock)->not->toBeFalse('the export never took a shared lock on the site')
        ->and($lock)->toBeLessThan($firstRead, 'the export read the person before it held the site lock');
});
