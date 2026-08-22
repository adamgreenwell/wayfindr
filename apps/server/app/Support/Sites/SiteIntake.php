<?php

namespace App\Support\Sites;

use App\Models\Site;

/**
 * What a site asks a visitor before the conversation starts.
 *
 * `visitors.name` and `visitors.email` have existed since the first migration
 * and are written by nothing at all. SDK identification writes `external_id`
 * and only that, so an anonymous visitor -- most traffic on most sites -- ends
 * a conversation with no way to be reached about it.
 *
 * Note this cannot ride in on `metadata.context`: VisitorContextSanitizer
 * deliberately strips anything that looks like an email address, and rightly
 * so, because that channel carries whatever a host page happens to hold. An
 * address a visitor typed into a form asking for it is a different thing, and
 * needs its own field.
 */
final class SiteIntake
{
    public const FIELDS = ['name', 'email', 'reason'];

    public const OFF = 'off';

    public const OPTIONAL = 'optional';

    public const REQUIRED = 'required';

    /** @param array<string, string> $fields */
    private function __construct(
        public readonly array $fields,
        public readonly ?string $intro,
    ) {}

    public static function for(Site $site): self
    {
        $config = is_array($site->settings['intake'] ?? null) ? $site->settings['intake'] : [];
        $stored = is_array($config['fields'] ?? null) ? $config['fields'] : [];

        $fields = [];

        foreach (self::FIELDS as $field) {
            $mode = $stored[$field] ?? self::OFF;

            // Anything unrecognised is off. A site that has never configured
            // intake must not start interrupting its visitors because a key was
            // misspelt.
            $fields[$field] = in_array($mode, [self::OPTIONAL, self::REQUIRED], true) ? $mode : self::OFF;
        }

        $intro = is_string($config['intro'] ?? null) ? trim($config['intro']) : '';

        return new self($fields, $intro === '' ? null : $intro);
    }

    public function asks(): bool
    {
        return $this->fields !== array_fill_keys(self::FIELDS, self::OFF);
    }

    public function isRequired(string $field): bool
    {
        return ($this->fields[$field] ?? self::OFF) === self::REQUIRED;
    }

    public function isEnabled(string $field): bool
    {
        return ($this->fields[$field] ?? self::OFF) !== self::OFF;
    }

    /**
     * What the widget needs to draw the form.
     *
     * The server still enforces every rule on the way in. This is what to show,
     * not what to trust.
     *
     * @param  bool  $away  Out of hours an email is the only way back to
     *                      somebody, so it is asked for even where the site
     *                      would normally leave it optional.
     * @return array{asks: bool, intro: string|null, fields: array<string, string>}
     */
    public function toPayload(bool $away): array
    {
        $fields = $this->fields;

        if ($away) {
            $fields['email'] = self::REQUIRED;
        }

        return [
            'asks' => $fields !== array_fill_keys(self::FIELDS, self::OFF),
            'intro' => $this->intro,
            'fields' => $fields,
        ];
    }

    /**
     * The rules the server applies, which are the ones that count.
     *
     * @return array<string, array<int, string>>
     */
    public function validationRules(bool $away): array
    {
        $rules = [];

        foreach (self::FIELDS as $field) {
            $mode = $field === 'email' && $away ? self::REQUIRED : ($this->fields[$field] ?? self::OFF);

            if ($mode === self::OFF) {
                // Not merely optional: a field this site does not ask for must
                // not be accepted from a crafted request either.
                $rules['visitor_'.$field] = ['prohibited'];

                continue;
            }

            $rules['visitor_'.$field] = array_merge(
                [$mode === self::REQUIRED ? 'required' : 'nullable', 'string'],
                $field === 'email' ? ['email:filter', 'max:255'] : ['max:255'],
            );
        }

        return $rules;
    }
}
