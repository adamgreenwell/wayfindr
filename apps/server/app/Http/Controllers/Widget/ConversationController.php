<?php

namespace App\Http\Controllers\Widget;

use App\Events\ConversationCreated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ProactiveMessageDelivery;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\ProactiveMessages\ProactiveConversationOpening;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Sites\WidgetLanguage;
use App\Support\VisitorContextSanitizer;
use App\Support\Visitors\VisitorIdentityResolver;
use App\Support\Visitors\VisitorPageUrl;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function store(
        Request $request,
        VisitorSessionToken $visitorSessionToken,
        VisitorContextSanitizer $visitorContextSanitizer,
        SiteManagerCoverage $siteManagerCoverage,
        VisitorIdentityResolver $visitorIdentityResolver,
        ProactiveConversationOpening $proactiveOpening,
    ): JsonResponse {
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
        $known = SiteIntake::knownFor($visitorIdentityResolver->forAnonymousId(
            (int) $site->id,
            (string) $request->input('anonymous_id'),
        ));

        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'subject' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'context' => ['nullable', 'array', 'max:50'],
            'proactive_message_delivery_id' => ['nullable', 'uuid'],
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
            $siteManagerCoverage,
            $validated,
            $visitorContextSanitizer,
            $visitorIdentityResolver,
            $proactiveOpening,
            $visitor,
        ): Conversation {
            // The synchronous conversation observer snapshots the account SLA
            // policy. Join the account-first protocol before taking the shared
            // site lock so an availability or archive write cannot deadlock
            // with this visitor request.
            $siteManagerCoverage->shareAccount((int) $site->account_id);

            // Same lock `updatePresence()` takes, for the same reason as
            // bootstrap: the page-address setting has to be the one in force
            // at the write, not the one this request saw on arrival.
            // A SHARED lock, not an exclusive one. These three readers only need
            // the setting to be stable across their own write; they do not conflict
            // with each other, and every visitor on the site takes this every 45
            // seconds. FOR UPDATE would serialise every heartbeat on a busy site
            // behind one row -- a convoy on the hottest path in the feature, to
            // protect against a revocation that happens once in a while.
            //
            // FOR SHARE still does the whole job: `Site::mutateSettings()` takes
            // the exclusive lock, so a revocation waits for the readers in flight
            // and blocks the ones after it. Readers exclude the writer; they do not
            // exclude each other.
            $current = Site::query()->servable()->whereKey($site->getKey())->sharedLock()->first();

            // Archive may have won the account lock after the public-key
            // lookup above. Decide from the locked row rather than falling
            // back to the stale pre-transaction site.
            abort_unless($current, 404, 'Site not found.');
            $storePageUrl = SitePresenceReporting::for($current)->pageUrls;

            $locked = Visitor::query()->whereKey($visitor->getKey())->lockForUpdate()->first();

            // Gone means the pruner won the race. Re-created rather than
            // failed: this visitor is here NOW, asking for help, which is the
            // one fact that outranks having been quiet for thirty days.
            // firstOrCreate, not create. The pruner deleting the row this
            // request resolved is one ordering; a heartbeat recreating it
            // before this transaction takes the lock is another, and then an
            // unconditional insert collides with the replacement on
            // `(site_id, anonymous_id)` and the visitor is told their message
            // could not be sent.
            if ($locked instanceof Visitor) {
                $visitor = $locked;
            } else {
                // An agent merge may have won the exclusive site lock after
                // token validation but before this transaction took its shared
                // lock. Re-resolve the browser ID here so the request follows
                // the newly-created alias instead of recreating the duplicate
                // row that the agent just removed.
                $visitor = $visitorIdentityResolver->forAnonymousId(
                    (int) $site->id,
                    $validated['anonymous_id'],
                ) ?? Visitor::query()->firstOrCreate([
                    'site_id' => $site->id,
                    'anonymous_id' => $validated['anonymous_id'],
                ]);
            }

            // Lock whatever that returned, unless we just made it. firstOrCreate
            // can hand back a row somebody else recreated under the same
            // `(site_id, anonymous_id)`, and merging metadata into an unlocked
            // copy of it is the race the lock above exists to settle -- a
            // heartbeat committing between that SELECT and this save would have
            // its page address overwritten by what was read here.
            if (! $visitor->wasRecentlyCreated) {
                $visitor = Visitor::query()->whereKey($visitor->getKey())->lockForUpdate()->first() ?? $visitor;
            }

            $visitor->forceFill([
                'metadata' => $visitorContextSanitizer->mergeMetadata(
                    $visitor->metadata,
                    $validated['page_url'] ?? null,
                    array_key_exists('context', $validated),
                    $validated['context'] ?? null,
                    // The LOCKED row's domain, not the one this request
                    // arrived holding. A site renamed between resolving it
                    // and taking the lock leaves the copy above naming a host
                    // that is no longer the site's own -- and an address is
                    // kept or dropped by exactly that comparison, so reading
                    // it from the stale copy stores a page from a host we no
                    // longer trust. $storePageUrl is already read from
                    // $current one screen up, for the same reason.
                    $current->domain,
                    $storePageUrl,
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

            $proactiveDelivery = $proactiveOpening->lockForVisitor(
                $validated['proactive_message_delivery_id'] ?? null,
                $current,
                $visitor,
            );
            $conversation = Conversation::query()->create([
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
                    // Gated on the same locked setting as the visitor's copy.
                    // The widget already omits the address, but the endpoint is
                    // public: a custom or older client keeps sending one, and
                    // this is the field that outlives the visitor row.
                    'started_page_url' => $storePageUrl
                        ? VisitorPageUrl::forSite($validated['page_url'] ?? null, $current->domain)
                        : null,
                    // The reason belongs to this conversation, not to the person:
                    // the next one may be about something else entirely. Name and
                    // email go on the visitor, where they are reusable.
                    'reason' => $this->trimmedOrNull($validated['visitor_reason'] ?? null),
                ], fn ($value): bool => $value !== null),
            ]);

            if ($proactiveDelivery instanceof ProactiveMessageDelivery) {
                $proactiveOpening->attach($proactiveDelivery, $conversation);
            }

            return $conversation;
        });

        event(new ConversationCreated($conversation));

        return response()->json([
            'data' => [
                'support_code' => $conversation->support_code,
                'status' => $conversation->status,
                'subject' => $conversation->subject,
                'visitor' => [
                    'anonymous_id' => $validated['anonymous_id'],
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
            ->when($visitor->exists, fn ($query) => $query->where('id', '!=', $visitor->getKey()))
            ->exists();

        return $belongsToAnotherVisitor ? [] : ['external_id' => $externalId];
    }
}
