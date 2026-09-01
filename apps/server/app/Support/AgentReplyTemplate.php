<?php

namespace App\Support;

final /*
 * Reached only from controllers and views, never a job or a mail build, so it
 * may use the catalogue directly -- see Ticket::attentionLabelKey().
 *
 * **Only the LABEL is translated. The body deliberately is not.**
 *
 * A label is dashboard chrome: it names the helper to the agent choosing it.
 * A body is a message to the VISITOR -- the composer drops it straight into the
 * reply box and the agent sends it. Translating it couples what a visitor
 * receives to the language their agent happens to read the dashboard in, so a
 * German-speaking agent would send German to an English visitor without
 * choosing to. The visitor's language is the widget's business (ADR 0017) and
 * has nothing to do with this preference.
 */ class AgentReplyTemplate
{
    /**
     * @return array<string, array{label: string, body: string}>
     */
    public static function options(): array
    {
        return [
            'looking_into_it' => [
                'label' => __('conversations.reply_templates.looking_into_it.label'),
                'body' => 'Thanks for the update. I am looking into this now and will follow up shortly.',
                // The label is chrome and follows the agent. The body is what the
                // VISITOR receives, so it is English and says so.
                'body_language' => DashboardLanguage::FALLBACK,
            ],
            'need_more_detail' => [
                'label' => __('conversations.reply_templates.need_more_detail.label'),
                'body' => 'Could you share a little more detail about what you expected to happen and what happened instead?',
                'body_language' => DashboardLanguage::FALLBACK,
            ],
            'confirm_resolution' => [
                'label' => __('conversations.reply_templates.confirm_resolution.label'),
                'body' => 'Thanks for your patience. I believe this is resolved now, but I am happy to keep digging if anything still looks off.',
                'body_language' => DashboardLanguage::FALLBACK,
            ],
            'ticket_follow_up' => [
                'label' => __('conversations.reply_templates.ticket_follow_up.label'),
                'body' => 'I turned this into a ticket so we can track the follow-up without losing the context from this conversation.',
                'body_language' => DashboardLanguage::FALLBACK,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::options());
    }

    public static function body(?string $template): ?string
    {
        if (! $template) {
            return null;
        }

        return self::options()[$template]['body'] ?? null;
    }
}
