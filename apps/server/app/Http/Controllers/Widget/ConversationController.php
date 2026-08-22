<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\VisitorContextSanitizer;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function store(Request $request, VisitorSessionToken $visitorSessionToken, VisitorContextSanitizer $visitorContextSanitizer): JsonResponse
    {
        // The site has to be resolved before the intake rules are known, and the
        // intake rules are part of validation -- so this runs in two passes
        // rather than one. The alternative is trusting the widget about what it
        // was asked to collect, which is not a thing a public endpoint may do.
        $site = WidgetSiteResolver::resolveOrFail((string) $request->input('site_public_key'));
        $intake = SiteIntake::for($site);
        $away = ! SiteAvailability::for($site)->open;
        // The same question bootstrap answered when it told the widget what to
        // draw, read from the record rather than from the request.
        $identified = Visitor::query()
            ->where('site_id', $site->id)
            ->where('anonymous_id', (string) $request->input('anonymous_id'))
            ->whereNotNull('external_id')
            ->exists();

        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'subject' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'context' => ['nullable', 'array', 'max:50'],
        ] + $intake->validationRules($away, $identified));

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        $visitor->forceFill([
            'metadata' => $visitorContextSanitizer->mergeMetadata(
                $visitor->metadata,
                $validated['page_url'] ?? null,
                array_key_exists('context', $validated),
                $validated['context'] ?? null,
            ),
            'last_seen_at' => now(),
        ]
            + $this->externalIdentifierUpdate($site, $visitor, $validated, $visitorContextSanitizer)
            + $this->intakeAnswers($validated))->save();

        $conversation = Conversation::query()->create([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'support_code' => $this->generateSupportCode(),
            'status' => 'open',
            'subject' => $validated['subject'] ?? null,
            'metadata' => array_filter([
                'started_page_url' => $validated['page_url'] ?? null,
                // The reason belongs to this conversation, not to the person:
                // the next one may be about something else entirely. Name and
                // email go on the visitor, where they are reusable.
                'reason' => $this->trimmedOrNull($validated['visitor_reason'] ?? null),
            ], fn ($value): bool => $value !== null),
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

    /**
     * Name and email land on the visitor, so the next visit already knows them.
     *
     * Only non-empty answers are written. An optional field left blank must not
     * erase what a previous conversation captured.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function intakeAnswers(array $validated): array
    {
        $answers = [];

        foreach (['name', 'email'] as $field) {
            $value = $this->trimmedOrNull($validated['visitor_'.$field] ?? null);

            if ($value !== null) {
                $answers[$field] = $value;
            }
        }

        return $answers;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
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
