<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\Sites\WidgetLanguage;
use App\Support\VisitorContextSanitizer;
use App\Support\Visitors\VisitorPageUrl;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function store(Request $request, VisitorSessionToken $visitorSessionToken, VisitorContextSanitizer $visitorContextSanitizer): JsonResponse
    {
        // The site has to be resolved before the intake rules are known, and the
        // intake rules are part of validation -- so this runs in two passes
        // rather than one. The alternative is trusting the widget about what it
        // was asked to collect, which is not a thing a public endpoint may do.
        $site = WidgetSiteResolver::resolveOrFail((string) $request->input('site_public_key'));

        // Before validate(), because the intake rules below are the first words
        // a NEW visitor reads from us and they are written by the framework --
        // no catch block reaches those.
        App::setLocale(WidgetLanguage::forVisitor($request->input('locale'), $site));
        $intake = SiteIntake::for($site);
        $away = ! SiteAvailability::for($site)->open;
        // What we already hold for this visitor, read from the record rather
        // than from the request -- the same answer bootstrap gave the widget
        // when it said which fields to draw.
        $known = SiteIntake::knownFor(Visitor::query()
            ->where('site_id', $site->id)
            ->where('anonymous_id', (string) $request->input('anonymous_id'))
            ->first());

        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'subject' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'context' => ['nullable', 'array', 'max:50'],
        ] + $intake->validationRules($away, $known));

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        // The stamp and the insert are ONE transaction, against a locked row.
        //
        // `wayfindr:prune-presence-visitors` deletes visitors who never made
        // contact, and it re-checks its predicates under a lock before doing
        // so -- which makes it safe against a writer that has already
        // committed, and not against this one. If the pruner takes the lock
        // first, this request waits, the delete commits, and then the save
        // below updates nothing while the insert that follows fails its
        // `visitor_id` foreign key. The visitor is told their message could
        // not be sent, for a reason that has nothing to do with them.
        //
        // Locking here puts both sides in the same queue. Whoever arrives
        // second sees what the first one did rather than a stale copy of the
        // world from before it started.
        $conversation = DB::transaction(function () use (

            $site,
            $validated,
            $visitorContextSanitizer,
            $visitor,
        ): Conversation {
            $locked = Visitor::query()->whereKey($visitor->getKey())->lockForUpdate()->first();

            // Gone means the pruner won the race. Re-created rather than
            // failed: this visitor is here NOW, asking for help, which is the
            // one fact that outranks having been quiet for thirty days.
            $visitor = $locked ?? Visitor::query()->create([
                'site_id' => $site->id,
                'anonymous_id' => $validated['anonymous_id'],
            ]);

            $visitor->forceFill([
                'metadata' => $visitorContextSanitizer->mergeMetadata(
                    $visitor->metadata,
                    $validated['page_url'] ?? null,
                    array_key_exists('context', $validated),
                    $validated['context'] ?? null,
                    $site->domain,
                ),
                'last_web_seen_at' => now(),
                // Starting a conversation is contact, and this route does not
                // require bootstrap to have run -- so the flag that means
                // "never made contact" has to be cleared here as well as
                // there. The pruner already refuses to delete anybody with a
                // conversation, so nothing was at risk; leaving it set would
                // still have been a record saying something untrue about
                // somebody who had just written in.
                'presence_only' => false,
            ]
                + $this->externalIdentifierUpdate($site, $visitor, $validated, $visitorContextSanitizer)
                + $this->intakeAnswers($validated))->save();

            return Conversation::query()->create([
                'site_id' => $site->id,
                'visitor_id' => $visitor->id,
                'support_code' => Conversation::generateSupportCode(),
                'status' => 'open',
                'subject' => $validated['subject'] ?? null,
                'metadata' => array_filter([
                    // Sanitised like the visitor's copy. This is the SECOND
                    // place the same URL lands, it is durable for the life of the
                    // conversation, and it is what the agent panels label the entry
                    // page -- so fixing only the visitor row would have left the
                    // likelier path open: people ask for help FROM the page that is
                    // going wrong, which on a reset flow is the page holding the
                    // token.
                    'started_page_url' => VisitorPageUrl::forSite($validated['page_url'] ?? null, $site->domain),
                    // The reason belongs to this conversation, not to the person:
                    // the next one may be about something else entirely. Name and
                    // email go on the visitor, where they are reusable.
                    'reason' => $this->trimmedOrNull($validated['visitor_reason'] ?? null),
                ], fn ($value): bool => $value !== null),
            ]);
        });

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
