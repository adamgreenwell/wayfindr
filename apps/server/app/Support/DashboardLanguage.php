<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The language the dashboard speaks to one agent.
 *
 * A per-agent choice rather than an account or install setting, because the
 * dashboard is read by people rather than by an organisation: a support team
 * spread across countries has agents who each want their own tools in their own
 * language, and none of them should have to argue with a colleague about it.
 *
 * Distinct from `WidgetLanguage`, which is the operator's guess at who VISITS.
 * The two lists happen to match today and are not required to: a desk can
 * perfectly well answer German visitors from an English-speaking dashboard.
 */
final class DashboardLanguage
{
    /**
     * Languages the dashboard carries a catalogue for.
     *
     * **Autonyms — each language named in its own language, never glossed.**
     * This list is deliberately NOT translated and is deliberately identical in
     * every rendering language, which is the one place on a translated page
     * where copy reading the same in English and German is correct.
     *
     * The reason is who reads it. This selector asks an agent which language
     * *they* want to read, so the reader of any option is by definition someone
     * who reads that language, and `Deutsch` is what they are looking for. The
     * gloss it used to carry — `Deutsch (German)` — helped exactly one audience,
     * English readers, and put an English word inside the German page for
     * everyone else.
     *
     * Contrast `Sites\WidgetLanguage`, which keeps its glosses on purpose: that
     * list asks an operator to choose a language for VISITORS, and the operator
     * may well not read it. Same words, different question.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'en' => 'English',
        'de' => 'Deutsch',
        'it' => 'Italiano',
    ];

    public const FALLBACK = 'en';

    /**
     * Route names whose copy has been through the extraction (#749).
     *
     * The dashboard is translated a surface at a time, so this list exists for
     * the length of the epic and then deletes itself.
     *
     * It scopes the LOCALE, not just the `lang` attribute, and that distinction
     * was the whole lesson. Switching the locale globally and marking only the
     * document put German fragments inside pages that correctly declared
     * themselves English -- a model's option labels here, a Carbon
     * `diffForHumans()` there, a validation message somewhere else. Each one is
     * a separate leak with a separate fix, and there is no end to the list.
     *
     * Scoping the locale answers all of them at once: on a surface that has not
     * been extracted, nothing is translated, so nothing can be inconsistent and
     * `lang="en"` is simply true.
     *
     * Since the app SHELL was extracted this decides the whole document rather
     * than only its main region -- the rail, topbar and search speak whatever
     * the route here says, which is why `<main>` and the breadcrumb no longer
     * carry a `lang` of their own.
     *
     * Write routes are here alongside their page because they render it back on
     * a validation failure.
     */
    public const EXTRACTED_ROUTES = [
        // The operator-console rollout now covers the dashboard, onboarding,
        // break-glass, and settings surfaces listed here. The shared shell uses
        // the same catalogue but stays English on every route not yet listed,
        // preserving the one-language-per-document boundary during rollout.
        'operator.dashboard',
        'operator.onboarding',
        // The platform side of break-glass: one request page, three read-only
        // viewers, and the writes whose validation or lifecycle result renders
        // back onto the request page.
        'operator.break-glass.index',
        'operator.break-glass.store',
        'operator.break-glass.approve',
        'operator.break-glass.close',
        'operator.break-glass.show',
        'operator.break-glass.conversations.show',
        'operator.break-glass.tickets.show',
        'operator.settings.localization.edit',
        'operator.settings.localization.update',
        'operator.settings.scanning.edit',
        'operator.settings.scanning.update',
        'operator.settings.scanning.test',
        'operator.settings.mail.edit',
        'operator.settings.mail.update',
        'operator.settings.mail.test',
        'operator.settings.webpush.edit',
        'operator.settings.webpush.update',
        'operator.settings.storage.edit',
        'operator.settings.storage.update',
        'operator.settings.storage.test',
        'operator.settings.backups.edit',
        'operator.settings.backups.history',
        'operator.settings.backups.update',
        'operator.settings.backups.test',
        'operator.settings.backups.run',
        'operator.settings.backups.restore',
        'operator.settings.backups.restore.run',

        'dashboard.profile.show',
        'dashboard.profile.update',
        'dashboard.profile.alerts.update',
        'dashboard.profile.push-subscription.store',
        'dashboard.profile.push-subscription.status',
        'dashboard.profile.push-subscription.destroy',
        'dashboard.profile.password.update',
        'dashboard.profile.two-factor.start',
        'dashboard.profile.two-factor.confirm',
        'dashboard.profile.two-factor.cancel',
        'dashboard.profile.two-factor.recovery-codes.regenerate',
        'dashboard.profile.two-factor.disable',
        'dashboard.account.security.show',
        'dashboard.account.security.update',
        'dashboard.account.sla-policies.index',
        'dashboard.account.sla-policies.update',
        'dashboard.conversations.index',
        'dashboard.conversations.bulk.preview',
        'dashboard.conversations.bulk.store',
        'dashboard.conversations.bulk.undo',
        'dashboard.tickets.index',
        'dashboard.tickets.bulk.preview',
        'dashboard.tickets.bulk.store',
        'dashboard.tickets.bulk.undo',

        // The alert centre and its two mark-read actions. Both writes can
        // return to this page with the active filter/query context intact, so
        // they share its request-scoped language boundary.
        'dashboard.alerts.index',
        'dashboard.alerts.read',
        'dashboard.alerts.read-all',

        // The reports page follows the reader; its CSV export deliberately
        // keeps stable English headers and machine-readable numeric cells.
        'dashboard.reports.index',

        // The sites directory and the new-site form. The store action belongs
        // here because validation renders back onto the form in the reader's
        // language; its success key is resolved only after the redirect.
        'dashboard.sites.index',
        'dashboard.sites.create',
        'dashboard.sites.store',

        // The hosted tester is a read-only utility with no form endpoints of
        // its own. Its fake visitor/site values remain language-neutral while
        // the page around them follows the agent running the verification.
        'dashboard.sites.tester',

        // Site Settings and every write that renders validation or lifecycle
        // feedback back onto it. Stored visitor-facing copy remains authored
        // data; only the agent-facing controls and explanations are localized.
        'dashboard.sites.show',
        'dashboard.sites.update',
        'dashboard.sites.intake.update',
        'dashboard.sites.rating.update',
        'dashboard.sites.presence.update',
        'dashboard.sites.language.update',
        'dashboard.sites.availability.update',
        'dashboard.sites.inbound-address.update',
        'dashboard.sites.appearance.update',
        'dashboard.sites.availability.close',
        'dashboard.sites.availability.reopen',
        'dashboard.sites.details.update',
        'dashboard.sites.archive',
        'dashboard.sites.unarchive',
        'dashboard.sites.purge',
        'dashboard.sites.support-agents.update',
        'dashboard.sites.external-issue-projects.store',
        'dashboard.sites.external-issue-projects.destroy',

        // The ticket workspace and endpoints owned exclusively by it. The
        // assignee and lifecycle writes are deliberately absent: those are
        // also submitted from the conversation's linked-ticket panel, so the
        // middleware resolves their locale from the page they render back to.
        'dashboard.tickets.show',
        'dashboard.tickets.update',
        'dashboard.tickets.notes.store',
        'dashboard.tickets.labels.store',
        'dashboard.tickets.labels.destroy',
        'dashboard.tickets.replies.store',
        'dashboard.tickets.external-links.store',
        'dashboard.tickets.external-links.destroy',
        'dashboard.tickets.external-issues.github.store',
        'dashboard.tickets.external-issues.gitlab.store',
        'dashboard.tickets.external-issues.jira.store',
        'dashboard.tickets.escalations.store',
        'dashboard.conversations.show',
        // The detail page's own endpoints. It replaces its transcript from
        // `messages.index` and posts replies to `messages.store`, and an
        // unlisted route renders English -- so a refreshed partial arrived in a
        // different language from the page around it.
        'dashboard.conversations.messages.index',
        'dashboard.conversations.messages.store',
        'dashboard.conversations.priority.update',
        // And the attachment endpoint. The composer prefers the response's own
        // message over its local fallback, so an oversized file answered in
        // English replaced the German live state on an ordinary upload.
        'dashboard.conversations.attachments.store',

        // The account reply-templates page. An agent reaches it from the reply
        // composer on a conversation, which is already extracted -- so before
        // this it was a German conversation and an English page one click away.
        'dashboard.account.reply-templates.index',
        'dashboard.account.reply-templates.store',
        'dashboard.account.reply-templates.update',
        'dashboard.account.reply-templates.archive',

        // Automation management is one translated workflow: the list and
        // execution log, the ordered rule form, and every write that can send
        // validation or preview feedback back to that form.
        'dashboard.account.automation-rules.index',
        'dashboard.account.automation-rules.create',
        'dashboard.account.automation-rules.store',
        'dashboard.account.automation-rules.edit',
        'dashboard.account.automation-rules.update',
        'dashboard.account.automation-rules.preview',
        'dashboard.account.automation-rules.destroy',
        'dashboard.account.automation-macros.create',
        'dashboard.account.automation-macros.store',
        'dashboard.account.automation-macros.edit',
        'dashboard.account.automation-macros.update',
        'dashboard.account.automation-macros.destroy',
        'dashboard.tickets.macros.run',
        'dashboard.conversations.macros.run',

        // Site-owned proactive-message configuration is part of the same
        // automation workflow. Its visitor-facing message and match strings
        // remain authored data; the controls around them follow the agent.
        'dashboard.sites.proactive-messages.index',
        'dashboard.sites.proactive-messages.create',
        'dashboard.sites.proactive-messages.store',
        'dashboard.sites.proactive-messages.edit',
        'dashboard.sites.proactive-messages.update',
        'dashboard.sites.proactive-messages.destroy',

        // The account ticket-labels page. Reached from the ticket queue, which
        // is extracted, and it links straight back there -- so English here put
        // two languages either side of one click.
        'dashboard.account.labels.index',
        'dashboard.account.labels.store',
        'dashboard.account.labels.update',
        'dashboard.account.labels.destroy',

        // The account articles pages -- the help-centre answers a visitor finds
        // in the widget. Both views, because extracting one of a pair puts the
        // flip inside the section instead of at its edge.
        'dashboard.account.articles.index',
        'dashboard.account.articles.store',
        'dashboard.account.articles.show',
        'dashboard.account.articles.update',
        'dashboard.account.articles.destroy',
        'dashboard.account.articles.publish',

        // The account API-tokens page. The surface that hands out credentials
        // was the one place an admin switched back to English -- where
        // misreading a sentence costs the most on this platform.
        'dashboard.account.api-tokens.index',
        'dashboard.account.api-tokens.store',
        'dashboard.account.api-tokens.destroy',
        'dashboard.account.outbound-webhooks.store',
        'dashboard.account.outbound-webhooks.destroy',
        'dashboard.account.outbound-webhooks.retry',

        // The account audit SCREEN. Its CSV export is intentionally absent:
        // the download keeps stable English headers and labels, plus a
        // sortable timestamp, for spreadsheets and scripts that may open it
        // under a different locale from the dashboard that produced it.
        'dashboard.account.audit.index',

        // The account side of operator access. The GET shows requests, active
        // access and history; all three writes redirect their lifecycle result
        // back onto that same page, so their flash keys belong to its locale.
        'dashboard.account.break-glass.index',
        'dashboard.account.break-glass.approve',
        'dashboard.account.break-glass.deny',
        'dashboard.account.break-glass.close',

        // The account integrations page and the two writes that belong only
        // to it. Connection creation is shared with Site Settings, so its
        // locale follows the referring page instead of living in this route
        // list independently.
        'dashboard.account.integrations',
        'dashboard.external-issue-provider-connections.webhook-secret.update',
        'dashboard.external-issue-provider-connections.capabilities.update',

        // The account overview and its roster actions. The page combines
        // role/access boundaries, readiness summaries, recent activity and
        // team management; extracting only the GET would put English
        // validation or lifecycle results back into the translated page.
        'dashboard.account.show',
        'dashboard.account.agents.store',
        'dashboard.account.agents.role.update',
        'dashboard.account.agents.deactivate',
        'dashboard.account.agents.reactivate',

        // The live-visitors board. Most of this page's copy is rendered by its
        // SCRIPT rather than by Blade, which is why it needed no new mechanism
        // and does need the render audit to open it with the board populated.
        'dashboard.sites.live',

        // The visitor directory and profile are one navigation path: the live
        // board already links to the profile, and translating only the list or
        // only the destination would put a language switch inside the section.
        'dashboard.visitors.index',
        'dashboard.visitors.show',
        'dashboard.visitors.merge',
        'dashboard.visitors.notes.store',
        'dashboard.visitors.notes.destroy',

        // Visitor attribute definitions are the management half of the
        // translated directory/profile workflow. Validation and success
        // feedback return to this page, so its writes share the same locale.
        'dashboard.account.visitor-attributes.index',
        'dashboard.account.visitor-attributes.store',
        'dashboard.account.visitor-attributes.update',
        'dashboard.account.visitor-attributes.destroy',
    ];

    /**
     * The locale to render a given request in.
     *
     * An agent's own language on a surface that can speak it, and English
     * everywhere else -- including for an install whose own default is German,
     * because an unextracted page has no German to show.
     */
    public static function forRequest(?User $agent, ?string $routeName, ?string $rendersBackTo = null): string
    {
        if ($routeName !== null && in_array($routeName, self::EXTRACTED_ROUTES, true)) {
            return self::for($agent);
        }

        // A write that renders back onto an extracted page belongs to that
        // page, whoever owns the endpoint.
        //
        // Listing the write route alongside its own page (above) only works
        // when the endpoint serves one surface. A linked-ticket action does
        // not: the same `AgentTicketController::close()` is submitted from the
        // ticket page and from the conversation panel, and its validation runs
        // before the redirect. Listing it would answer in German on the English
        // ticket page; not listing it put English errors on the German
        // conversation page. Neither is a locale for the endpoint to have --
        // the language belongs to whichever surface will render the answer.
        return $rendersBackTo !== null && in_array($rendersBackTo, self::EXTRACTED_ROUTES, true)
            ? self::for($agent)
            : self::FALLBACK;
    }

    /**
     * The language for content that is STORED rather than rendered.
     *
     * A ticket's subject and description are written once and read by everyone
     * -- other agents on other language settings, notification emails, the API,
     * and whatever external issue tracker the account has linked. Generating
     * them in the creating agent's language puts one person's dashboard
     * preference into shared data permanently, where nothing can translate it
     * back.
     *
     * The install's own language is the neutral answer: it is what every
     * unextracted surface already renders, and it does not change with whoever
     * happened to press the button.
     */
    public static function forStoredContent(): string
    {
        return self::for(null);
    }

    /**
     * The locale to render for this agent, always something we can render.
     *
     * Null preference means the install default rather than a broken page --
     * every agent predates this setting, so null is the common case and has to
     * be the safe one.
     */
    public static function for(?User $agent): string
    {
        return self::normalise($agent?->locale)
            // "Use the install default" has to mean the install's default. An
            // operator who set APP_LOCALE=de and left every agent unset -- which
            // is every agent on an upgraded install -- got English from a
            // hard-coded fallback, so the option did the one thing it names.
            //
            // Read from our own config key, NOT from `app.locale`:
            // `App::setLocale()` mutates that one, so after a request rendered
            // for a German agent it says "de", and the next agent with no
            // preference silently inherits a language they never chose.
            ?? self::normalise(config('wayfindr.dashboard_locale'))
            ?? self::FALLBACK;
    }

    /**
     * A supported locale, or null when it is not one we can render.
     */
    public static function normalise(mixed $locale): ?string
    {
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        // `de-DE` and `de_DE` both mean German here. The dashboard does not
        // carry regional variants, and refusing one because of its suffix
        // would be pedantry rather than accuracy.
        $base = strtolower(str_replace('_', '-', trim($locale)));
        $base = explode('-', $base)[0];

        return array_key_exists($base, self::SUPPORTED) ? $base : null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::SUPPORTED;
    }
}
