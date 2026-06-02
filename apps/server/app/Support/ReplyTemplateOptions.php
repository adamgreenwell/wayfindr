<?php

namespace App\Support;

use App\Models\ReplyTemplate;
use App\Models\User;

final class ReplyTemplateOptions
{
    /**
     * @return array<string, array{label: string, body: string, managed_id?: int}>
     */
    public function forAgent(User $agent): array
    {
        $account = $agent->account()->first();

        if (! $account) {
            return AgentReplyTemplate::options();
        }

        $managedTemplates = $account->replyTemplates()
            ->active()
            ->orderBy('name')
            ->get();

        if ($managedTemplates->isEmpty()) {
            return AgentReplyTemplate::options();
        }

        return $managedTemplates
            ->mapWithKeys(fn (ReplyTemplate $template): array => [
                $this->managedKey($template) => [
                    'label' => $template->name,
                    'body' => $template->body,
                    'managed_id' => $template->id,
                ],
            ])
            ->all();
    }

    /**
     * @return array{key: string, label: string, body: string, managed_id?: int}|null
     */
    public function resolve(User $agent, ?string $selectedTemplate): ?array
    {
        if (! $selectedTemplate) {
            return null;
        }

        $options = $this->forAgent($agent);

        if (! array_key_exists($selectedTemplate, $options)) {
            return null;
        }

        return [
            'key' => $selectedTemplate,
            ...$options[$selectedTemplate],
        ];
    }

    private function managedKey(ReplyTemplate $template): string
    {
        return 'managed:'.$template->id;
    }
}
