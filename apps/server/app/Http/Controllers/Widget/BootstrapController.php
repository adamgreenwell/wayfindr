<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request, VisitorSessionToken $visitorSessionToken, VisitorContextSanitizer $visitorContextSanitizer): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'context' => ['nullable', 'array', 'max:50'],
        ]);

        $site = Site::query()
            ->where('public_key', $validated['site_public_key'])
            ->first();

        abort_unless($site, 404, 'Site not found.');

        $visitor = Visitor::query()->firstOrNew([
            'site_id' => $site->id,
            'anonymous_id' => $validated['anonymous_id'],
        ]);

        $visitor->forceFill([
            'metadata' => $visitorContextSanitizer->mergeMetadata(
                $visitor->metadata,
                $validated['page_url'] ?? null,
                array_key_exists('context', $validated),
                $validated['context'] ?? null,
            ),
            'last_seen_at' => now(),
        ] + $this->externalIdentifierUpdate($site, $visitor, $validated, $visitorContextSanitizer))->save();

        return response()->json([
            'data' => [
                'site' => $this->sitePayload($site),
                'visitor' => [
                    'anonymous_id' => $visitor->anonymous_id,
                    'token' => $visitorSessionToken->issue($site, $visitor),
                ],
            ],
        ], $visitor->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array{name: string, domain: string|null, public_key: string, settings: array{mask_selectors: array<int, string>, mask_terms: array<int, string>}}
     */
    private function sitePayload(Site $site): array
    {
        return [
            'name' => $site->name,
            'domain' => $site->domain,
            'public_key' => $site->public_key,
            'settings' => [
                'mask_selectors' => $this->stringList($site->settings['mask_selectors'] ?? []),
                'mask_terms' => $this->stringList($site->settings['mask_terms'] ?? []),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
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
