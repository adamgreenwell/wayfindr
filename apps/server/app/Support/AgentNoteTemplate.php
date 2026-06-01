<?php

namespace App\Support;

final class AgentNoteTemplate
{
    /**
     * @return array<string, array{label: string, body: string}>
     */
    public static function options(): array
    {
        return [
            'handoff_summary' => [
                'label' => 'Handoff summary',
                'body' => 'Handoff summary: include what was tried, current customer impact, and the next recommended step.',
            ],
            'waiting_on_visitor' => [
                'label' => 'Waiting on visitor',
                'body' => 'Waiting on visitor for more detail before this ticket can move forward.',
            ],
            'escalation_context' => [
                'label' => 'Escalation context',
                'body' => 'Escalation context: capture impact, affected site, reproduction steps, and safe links before escalating.',
            ],
            'resolution_summary' => [
                'label' => 'Resolution summary',
                'body' => 'Resolution summary: describe the fix, confirmation signal, and any follow-up needed.',
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
