<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteRatingTemplate;
use App\Models\SiteRatingTemplateVersion;
use App\Support\Sites\RatingTemplateDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A site's own rating template: edit a draft, preview it, publish it (#1157).
 *
 * Two deliberate separations:
 *
 * - **Draft from published.** Saving the draft changes nothing a visitor sees.
 *   Only publishing does, and publishing never rewrites: it adds the next
 *   numbered version, so every answer already given keeps the exact question
 *   it was answered under.
 * - **Publishing from selecting.** Publishing makes a version available; the
 *   site's rating settings decide whether it is the one asked. A site can go
 *   back to a built-in question, or stop asking, without losing a definition
 *   or an answer.
 */
class SiteRatingTemplateController extends Controller
{
    public function edit(Request $request, Site $site): View
    {
        $this->authorize($request, $site, 'view', 404);

        $template = SiteRatingTemplate::query()->where('site_id', $site->id)->first();

        return view('agent.sites.rating-template', [
            'account' => $request->user()->account()->firstOrFail(),
            'agent' => $request->user(),
            'site' => $site,
            'draft' => $template?->definition() ?? RatingTemplateDefinition::starter(),
            'versions' => SiteRatingTemplateVersion::query()
                ->where('site_id', $site->id)
                ->with('publishedBy:id,name')
                ->orderByDesc('version')
                ->get(),
            'canUpdate' => Gate::forUser($request->user())->allows('update', $site),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize($request, $site, 'view', 404);
        $this->authorize($request, $site, 'update');

        $definition = $this->validated($request);

        SiteRatingTemplate::query()->updateOrCreate(
            ['site_id' => $site->id],
            ['draft' => $definition->toArray()],
        );

        return redirect()
            ->route('dashboard.sites.rating-template.edit', $site)
            ->with('status', 'rating_templates.flash.draft_saved');
    }

    public function publish(Request $request, Site $site): RedirectResponse
    {
        $this->authorize($request, $site, 'view', 404);
        $this->authorize($request, $site, 'update');

        $version = DB::transaction(function () use ($request, $site): int {
            // Serialised on the site row, so two admins publishing at once get
            // two distinct numbers rather than one unique-index failure.
            Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

            $template = SiteRatingTemplate::query()->where('site_id', $site->id)->first();

            if ($template === null) {
                throw ValidationException::withMessages([
                    'publish' => __('rating_templates.publish.nothing'),
                ]);
            }

            // Validated again rather than trusted: what is published is what
            // every later answer is read against.
            $definition = RatingTemplateDefinition::fromInput($template->draft);
            $next = (int) SiteRatingTemplateVersion::query()->where('site_id', $site->id)->max('version') + 1;

            SiteRatingTemplateVersion::query()->create([
                'site_id' => $site->id,
                'version' => $next,
                'definition' => $definition->toArray(),
                'published_by_id' => $request->user()->id,
                'published_at' => now(),
            ]);

            return $next;
        });

        return redirect()
            ->route('dashboard.sites.rating-template.edit', $site)
            ->with('status', 'rating_templates.flash.published')
            ->with('published_version', $version);
    }

    private function validated(Request $request): RatingTemplateDefinition
    {
        $request->validate(['draft' => ['required', 'array']]);

        try {
            return RatingTemplateDefinition::fromInput($request->input('draft'));
        } catch (ValidationException $exception) {
            // Keyed like the form's own fields, so each message lands beside
            // the input it is about.
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors['draft.'.$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function authorize(Request $request, Site $site, string $ability, int $status = 403): void
    {
        abort_unless($request->user() && Gate::forUser($request->user())->allows($ability, $site), $status);
    }
}
