<?php

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Whether this site asks a visitor how it went, once a conversation closes.
 *
 * Off unless an operator turns it on. A question nobody chose to ask is an
 * interruption, and the answer to an unasked question is worth nothing anyway.
 */
final class SiteRatingPrompt
{
    private function __construct(
        public readonly bool $enabled,
        public readonly ?string $intro,
    ) {}

    public static function for(Site $site): self
    {
        $config = is_array($site->settings['rating'] ?? null) ? $site->settings['rating'] : [];
        $intro = is_string($config['intro'] ?? null) ? trim($config['intro']) : '';

        return new self(
            ($config['enabled'] ?? false) === true,
            $intro === '' ? null : mb_substr($intro, 0, 160),
        );
    }

    /**
     * @return array{asks: bool, intro: string|null}
     */
    public function toPayload(): array
    {
        return ['asks' => $this->enabled, 'intro' => $this->intro];
    }
}
