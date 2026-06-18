<?php

namespace App\Http\Controllers\Widget;

use App\Events\CobrowseStateUpdated;
use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\CobrowsePayloadBudget;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseMutationController extends Controller
{
    public function store(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'page_url' => ['required', 'string', 'max:2048'],
            'sequence' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'dropped_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'skipped_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'mutations' => ['required', 'array', 'max:'.CobrowsePayloadBudget::MUTATION_BATCH_MAX_ITEMS],
            'mutations.*.type' => ['required', 'string', 'in:text,attribute,added,removed'],
            'mutations.*.path' => ['required', 'string', 'max:1024'],
            'mutations.*.text' => ['nullable', 'string', 'max:'.CobrowsePayloadBudget::MUTATION_TEXT_MAX_CHARACTERS],
            'mutations.*.html' => ['nullable', 'string', 'max:'.CobrowsePayloadBudget::MUTATION_HTML_MAX_CHARACTERS],
            'mutations.*.attribute_name' => ['nullable', 'string', 'max:128'],
            'mutations.*.attribute_value' => ['nullable', 'string', 'max:'.CobrowsePayloadBudget::MUTATION_ATTRIBUTE_VALUE_MAX_CHARACTERS],
            'mutations.*.node_name' => ['nullable', 'string', 'max:64'],
            'mutations.*.node_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mutations.*.masked_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $site = Site::query()
            ->where('public_key', $validated['site_public_key'])
            ->first();

        abort_unless($site, 404, 'Site not found.');

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->first();

        abort_unless($conversation, 404, 'Conversation not found.');

        $cobrowseSession = CobrowseSession::query()
            ->where('conversation_id', $conversation->id)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->where('status', 'granted')
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        abort_unless($cobrowseSession, 404, 'Cobrowse session not active.');

        $mutationSummary = [];

        $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata) use ($validated, &$mutationSummary): array {
            $mutationSummary = $this->mutationSummary($metadata, $validated);
            $metadata['mutations'] = $mutationSummary;
            $metadata['payload_budget'] = CobrowsePayloadBudget::limits();

            return $metadata;
        });

        event(new CobrowseStateUpdated($cobrowseSession, 'mutations'));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => [
                    'status' => $cobrowseSession->status,
                ],
                'payload_budget' => CobrowsePayloadBudget::limits(),
                'mutations' => [
                    'last_sequence' => $mutationSummary['last_sequence'],
                    'batch_count' => $mutationSummary['batch_count'],
                    'mutation_count' => $mutationSummary['mutation_count'],
                    'dropped_count' => $mutationSummary['dropped_count'],
                    'skipped_count' => $mutationSummary['skipped_count'],
                    'recent_batches_count' => count($mutationSummary['recent_batches']),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mutationSummary(array $metadata, array $validated): array
    {
        $previous = $metadata['mutations'] ?? [];
        $mutations = $this->normalizeMutations($validated['mutations']);
        $droppedCount = (int) ($validated['dropped_count'] ?? 0);
        $skippedCount = (int) ($validated['skipped_count'] ?? 0);

        $batch = [
            'sequence' => (int) $validated['sequence'],
            'mutation_count' => count($mutations),
            'dropped_count' => $droppedCount,
            'skipped_count' => $skippedCount,
            'page_url' => $validated['page_url'],
            'reported_at' => now()->toJSON(),
            'mutations' => $mutations,
        ];

        $recentBatches = is_array($previous['recent_batches'] ?? null)
            ? $previous['recent_batches']
            : [];

        $recentBatches[] = $batch;
        $recentBatches = array_slice($recentBatches, -CobrowsePayloadBudget::MUTATION_RECENT_BATCHES_RETAINED);

        return [
            'last_sequence' => $batch['sequence'],
            'batch_count' => ((int) ($previous['batch_count'] ?? 0)) + 1,
            'mutation_count' => ((int) ($previous['mutation_count'] ?? 0)) + $batch['mutation_count'],
            'dropped_count' => ((int) ($previous['dropped_count'] ?? 0)) + $droppedCount,
            'skipped_count' => ((int) ($previous['skipped_count'] ?? 0)) + $skippedCount,
            'last_page_url' => $validated['page_url'],
            'last_reported_at' => $batch['reported_at'],
            'recent_batches' => $recentBatches,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mutations
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMutations(array $mutations): array
    {
        return collect($mutations)
            ->map(function (array $mutation): array {
                return array_filter([
                    'type' => $mutation['type'],
                    'path' => $mutation['path'],
                    'text' => $mutation['text'] ?? null,
                    'html' => $mutation['html'] ?? null,
                    'attribute_name' => $mutation['attribute_name'] ?? null,
                    'attribute_value' => $mutation['attribute_value'] ?? null,
                    'node_name' => $mutation['node_name'] ?? null,
                    'node_count' => $mutation['node_count'] ?? null,
                    'masked_count' => $mutation['masked_count'] ?? null,
                ], fn (mixed $value): bool => $value !== null);
            })
            ->values()
            ->all();
    }
}
