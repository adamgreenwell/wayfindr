<?php

namespace App\Support;

final /*
 * Reached only from controllers and views, never a job or a mail build, so it
 * may use the catalogue directly -- see Ticket::attentionLabelKey() for the
 * distinction.
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
                'body' => __('conversations.reply_templates.looking_into_it.body'),
            ],
            'need_more_detail' => [
                'label' => __('conversations.reply_templates.need_more_detail.label'),
                'body' => __('conversations.reply_templates.need_more_detail.body'),
            ],
            'confirm_resolution' => [
                'label' => __('conversations.reply_templates.confirm_resolution.label'),
                'body' => __('conversations.reply_templates.confirm_resolution.body'),
            ],
            'ticket_follow_up' => [
                'label' => __('conversations.reply_templates.ticket_follow_up.label'),
                'body' => __('conversations.reply_templates.ticket_follow_up.body'),
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
