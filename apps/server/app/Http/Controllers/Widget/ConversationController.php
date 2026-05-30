<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function store(Request $request, VisitorSessionToken $visitorSessionToken, VisitorContextSanitizer $visitorContextSanitizer): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'subject' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'context' => ['nullable', 'array', 'max:50'],
        ]);

        $site = Site::query()
            ->where('public_key', $validated['site_public_key'])
            ->first();

        abort_unless($site, 404, 'Site not found.');

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        $visitor->forceFill([
            'metadata' => $visitorContextSanitizer->mergeMetadata(
                $visitor->metadata,
                $validated['page_url'] ?? null,
                array_key_exists('context', $validated),
                $validated['context'] ?? null,
            ),
            'last_seen_at' => now(),
        ] + $this->externalIdentifierUpdate($site, $visitor, $validated, $visitorContextSanitizer))->save();

        $conversation = Conversation::query()->create([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'support_code' => $this->generateSupportCode(),
            'status' => 'open',
            'subject' => $validated['subject'] ?? null,
            'metadata' => [
                'started_page_url' => $validated['page_url'] ?? null,
            ],
        ]);

        return response()->json([
            'data' => [
                'support_code' => $conversation->support_code,
                'status' => $conversation->status,
                'subject' => $conversation->subject,
                'visitor' => [
                    'anonymous_id' => $visitor->anonymous_id,
                ],
            ],
        ], 201);
    }

    private function generateSupportCode(): string
    {
        do {
            $supportCode = 'WF-'.Str::upper(Str::random(8));
        } while (Conversation::query()->where('support_code', $supportCode)->exists());

        return $supportCode;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{external_id?: string}
     */
    private function externalIdentifierUpdate(Site $site, Visitor $visitor, array $validated, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        if (! array_key_exists('external_id', $validated)) {
            return [];
        }

        $externalId = $visitorContextSanitizer->sanitizeIdentifier($validated['external_id']);

        if ($externalId === null) {
            return [];
        }

        $belongsToAnotherVisitor = Visitor::query()
            ->where('site_id', $site->id)
            ->where('external_id', $externalId)
            ->where('anonymous_id', '!=', $visitor->anonymous_id)
            ->exists();

        return $belongsToAnotherVisitor ? [] : ['external_id' => $externalId];
    }
}
