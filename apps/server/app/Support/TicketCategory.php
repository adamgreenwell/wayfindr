<?php

namespace App\Support;

final class TicketCategory
{
    /**
     * @return array<string, array{label: string, description: string, guidance: string}>
     */
    public static function options(): array
    {
        return [
            'question' => [
                'label' => 'Question',
                'description' => 'General question or how-to help.',
                'guidance' => 'Use for: clarification, product guidance, or "how do I?" support.',
            ],
            'bug' => [
                'label' => 'Bug',
                'description' => 'Something broken or not working as expected.',
                'guidance' => 'Use for: broken, unexpected, or reproducible behavior.',
            ],
            'billing' => [
                'label' => 'Billing',
                'description' => 'Pricing, invoice, payment, or account billing issue.',
                'guidance' => 'Use for: pricing, invoices, payments, renewals, or billing-account changes.',
            ],
            'access' => [
                'label' => 'Access',
                'description' => 'Login, permissions, or account access issue.',
                'guidance' => 'Use for: login, roles, locked accounts, permissions, or identity/access issues.',
            ],
            'task' => [
                'label' => 'Task',
                'description' => 'Follow-up work, configuration, or operational request.',
                'guidance' => 'Use for: setup, configuration, operational work, or planned follow-up.',
            ],
            'other' => [
                'label' => 'Other',
                'description' => 'Anything that does not fit the other categories.',
                'guidance' => 'Use sparingly; add context so it can be recategorized later.',
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

    public static function label(?string $category): string
    {
        if (! $category) {
            return 'Uncategorized';
        }

        return self::options()[$category]['label'] ?? ucfirst($category);
    }
}
