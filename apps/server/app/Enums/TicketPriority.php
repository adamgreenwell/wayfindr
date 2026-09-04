<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * @return array<string, array{label: string, description: string, agent_action: string}>
     */
    public static function options(): array
    {
        return [
            self::Low->value => [
                'label' => 'Low',
                'description' => 'Nice-to-have follow-up or non-blocking question.',
                'agent_action' => 'handle after active visitor blockers.',
            ],
            self::Normal->value => [
                'label' => 'Normal',
                'description' => 'Standard support request with no immediate deadline.',
                'agent_action' => 'answer in normal queue order.',
            ],
            self::High->value => [
                'label' => 'High',
                'description' => 'Time-sensitive issue affecting an important customer workflow.',
                'agent_action' => 'keep it moving today.',
            ],
            self::Urgent->value => [
                'label' => 'Urgent',
                'description' => 'Business-critical, active outage, or blocked production work.',
                'agent_action' => 'assign immediately and keep the visitor updated.',
            ],
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, array{label: string, description: string, agent_action: string}>
     */
    public static function guidanceOptions(): array
    {
        $options = self::options();

        return [
            self::Urgent->value => $options[self::Urgent->value],
            self::High->value => $options[self::High->value],
            self::Normal->value => $options[self::Normal->value],
            self::Low->value => $options[self::Low->value],
        ];
    }

    public static function label(self|string $priority): string
    {
        $value = $priority instanceof self ? $priority->value : $priority;

        return self::options()[$value]['label'] ?? ucfirst($value);
    }
}
