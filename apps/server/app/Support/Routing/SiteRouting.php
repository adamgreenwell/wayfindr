<?php

namespace App\Support\Routing;

use App\Models\Site;

final readonly class SiteRouting
{
    public const DEFAULT_CONVERSATION_CAPACITY = 5;

    public const MAX_CONVERSATION_CAPACITY = 100;

    public function __construct(
        public bool $enabled,
        public int $conversationCapacity,
    ) {}

    public static function for(Site $site): self
    {
        $settings = data_get($site->settings, 'routing');
        $settings = is_array($settings) ? $settings : [];
        $capacity = filter_var($settings['conversation_capacity'] ?? null, FILTER_VALIDATE_INT);

        return new self(
            enabled: ($settings['enabled'] ?? false) === true,
            conversationCapacity: is_int($capacity)
                ? min(max($capacity, 1), self::MAX_CONVERSATION_CAPACITY)
                : self::DEFAULT_CONVERSATION_CAPACITY,
        );
    }
}
