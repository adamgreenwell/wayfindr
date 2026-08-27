<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Sites\SiteRatingPrompt;
use App\Support\Sites\WidgetAppearance;
use App\Support\Sites\WidgetLanguage;
use App\Support\VisitorContextSanitizer;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        // Retried once on a duplicate key. A new visitor's first page load can
        // put bootstrap and the first heartbeat in flight together -- both read
        // no row, both insert, and `(site_id, anonymous_id)` lets exactly one
        // win. The presence endpoint already survives losing; this one returned
        // a 500 and the widget never got its configuration.
        //
        // Once and no more: after a conflict the row exists, so a second
        // failure is some other constraint and a loop would make it a hot one
        // on a public endpoint.
        try {
            // Wrapped for the same reason as VisitorPresenceReport: on
            // PostgreSQL a constraint violation aborts the surrounding
            // transaction, so the retry would run on a connection that refuses
            // every statement. DB::transaction() is a real transaction standing
            // alone and a SAVEPOINT inside a caller's, and either is enough to
            // leave something usable to retry on.
            $visitor = DB::transaction(fn (): Visitor => $this->stampVisitor($site, $validated, $visitorContextSanitizer));
        } catch (UniqueConstraintViolationException) {
            $visitor = $this->stampVisitor($site, $validated, $visitorContextSanitizer);
        }

        return response()->json([
            'data' => [
                'site' => $this->sitePayload($site, SiteIntake::knownFor($visitor)),
                'visitor' => [
                    'anonymous_id' => $visitor->anonymous_id,
                    'token' => $visitorSessionToken->issue($site, $visitor),
                    // Whether the host app told us who this is, as the SERVER
                    // sees it. The widget's own option can be set while the
                    // value was rejected -- sanitised away, or already claimed
                    // by another visitor -- and asking a logged-in customer for
                    // their name is the fastest way to look unfinished.
                    'identified' => $visitor->external_id !== null,
                ],
            ],
        ], $visitor->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array{name: string, domain: string|null, color: string, public_key: string, availability: array{away: bool, message: string|null, opens_at: string|null, timezone: string}, intake: array{asks: bool, intro: string|null, fields: array<string, string>}, settings: array{mask_selectors: array<int, string>, mask_terms: array<int, string>}}
     */
    /** @param array<string, bool> $known */
    /**
     * @param  array<string, mixed>  $validated
     */
    private function stampVisitor(Site $site, array $validated, VisitorContextSanitizer $visitorContextSanitizer): Visitor
    {
        $visitor = Visitor::query()->firstOrNew([
            'site_id' => $site->id,
            'anonymous_id' => $validated['anonymous_id'],
        ]);

        // Locked, and re-created if the lock finds nothing.
        //
        // The pruner deletes visitors who never made contact, and this is the
        // endpoint that decides somebody HAS. If the delete lands between the
        // read above and the write below, Eloquent does not complain: an update
        // matching zero rows is a successful save. Bootstrap would then answer
        // 200 and issue a session token naming a visitor that no longer exists,
        // and every conversation and message request afterwards would fail
        // token resolution with a 401 the visitor cannot do anything about.
        //
        // Worse than an error, because it looks like success right up until
        // they try to say something.
        if ($visitor->exists) {
            $locked = Visitor::query()->whereKey($visitor->getKey())->lockForUpdate()->first();

            $visitor = $locked ?? Visitor::query()->newModelInstance([
                'site_id' => $site->id,
                'anonymous_id' => $validated['anonymous_id'],
            ]);
        }

        $visitor->forceFill([
            'metadata' => $visitorContextSanitizer->mergeMetadata(
                $visitor->metadata,
                $validated['page_url'] ?? null,
                array_key_exists('context', $validated),
                $validated['context'] ?? null,
                $site->domain,
                SitePresenceReporting::for($site)->pageUrls,
            ),
            'last_web_seen_at' => now(),
            // Opening the widget IS making contact -- ADR 0016 §1 says so -- and
            // this is the endpoint that means it. A row that existed only
            // because somebody loaded a page stops being presence-only the
            // moment they open the panel, and stops being prunable with it.
            'presence_only' => false,
        ] + $this->externalIdentifierUpdate($site, $visitor, $validated, $visitorContextSanitizer))->save();

        return $visitor;
    }

    private function sitePayload(Site $site, array $known): array
    {
        $availability = SiteAvailability::for($site);

        return [
            'name' => $site->name,
            'domain' => $site->domain,
            // The token KEY, not a colour value (ADR 0014). The widget resolves
            // it through --wf-site-<key>, so the theme-tuned dark variant applies
            // and an operator can recolour a site without a widget redeploy.
            'color' => $site->resolvedColor()->value,
            'public_key' => $site->public_key,
            // A default, not an instruction. The widget puts this behind the
            // host page's own choice and the visitor's browser -- it decides
            // only when nobody better has spoken.
            'locale' => WidgetLanguage::for($site),
            // Derived here rather than in the widget: the desk's timezone and
            // schedule are the server's to know, and sending them would let a
            // visitor's wrong clock decide whether support looks open.
            'availability' => $availability->toPayload(),
            // What to ask before the conversation starts. Out of hours an email
            // is promoted to required here, because it is the only way back to
            // somebody -- the server applies the same promotion on the way in.
            'intake' => SiteIntake::for($site)->toPayload(! $availability->open, $known),
            // What this site looks like and says. Absent keys mean "as it always
            // was", so a site that configures nothing renders unchanged.
            'appearance' => WidgetAppearance::for($site)->toPayload(),
            // Whether this site asks how it went once a conversation closes.
            'rating' => SiteRatingPrompt::for($site)->toPayload(),
            // Whether this desk has written anything a visitor could find.
            // Carried here rather than discovered by a search on every open:
            // bootstrap already runs then, and a search box that never finds
            // anything is worse than no search box.
            'articles' => ['available' => Article::query()
                ->where('account_id', $site->account_id)
                ->published()
                ->exists()],
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
