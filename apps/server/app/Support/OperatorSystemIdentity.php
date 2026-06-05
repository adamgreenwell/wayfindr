<?php

namespace App\Support;

use Illuminate\Foundation\Application as LaravelApplication;

class OperatorSystemIdentity
{
    /**
     * @return array{
     *     docs: array<int, array{description: string, label: string, url: string}>,
     *     items: array<int, array{label: string, value: string}>
     * }
     */
    public function summary(): array
    {
        return [
            'items' => [
                $this->item('Wayfindr version', $this->configValue('wayfindr.release.version')),
                $this->item('Source revision', $this->configValue('wayfindr.release.commit')),
                $this->item('Environment', app()->environment()),
                $this->item('Debug mode', config('app.debug') ? 'Enabled' : 'Disabled'),
                $this->item('PHP version', PHP_VERSION),
                $this->item('Laravel version', LaravelApplication::VERSION),
                $this->item('Queue driver', $this->configValue('queue.default')),
                $this->item('Broadcast driver', $this->configValue('broadcasting.default')),
            ],
            'docs' => [
                [
                    'description' => 'Baseline install notes, operator responsibilities, and production process expectations.',
                    'label' => 'Self-hosting docs',
                    'url' => $this->configValue('wayfindr.documentation.self_hosting_url'),
                ],
                [
                    'description' => 'Generic Laravel runtime shape for non-Forge hosts, process managers, and container platforms.',
                    'label' => 'Runtime requirements',
                    'url' => $this->configValue('wayfindr.documentation.runtime_requirements_url'),
                ],
                [
                    'description' => 'Forge-specific deployment settings, process setup, and smoke-test guidance.',
                    'label' => 'Forge deploy guide',
                    'url' => $this->configValue('wayfindr.documentation.forge_url'),
                ],
            ],
        ];
    }

    /**
     * @return array{label: string, value: string}
     */
    private function item(string $label, string $value): array
    {
        return [
            'label' => $label,
            'value' => $value,
        ];
    }

    private function configValue(string $key): string
    {
        $value = config($key);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return 'Not configured';
    }
}
