<?php

namespace App\Support\Sites;

/**
 * The built-in support-rating templates a site can ask with (#1156).
 *
 * A template is identified by key AND version, and a response records both, so
 * what an answer means is fixed by what the visitor was shown rather than by
 * whatever the site is set to now. A built-in version is never rewritten: a
 * change to a question's meaning is a new version.
 */
final class RatingTemplate
{
    /** The single overall question every site asked before templates existed. */
    public const OVERALL = 'overall';

    /** Overall experience, the named agent's help, and a comment. */
    public const STARTER = 'starter';

    /** @var array<string, list<int>> */
    private const VERSIONS = [
        self::OVERALL => [1],
        self::STARTER => [1],
    ];

    public const AGENT_SCORES = ['good', 'ok', 'bad', 'not_applicable'];

    private function __construct(
        public readonly string $key,
        public readonly int $version,
    ) {}

    public static function current(string $key): self
    {
        $versions = self::VERSIONS[$key] ?? self::VERSIONS[self::OVERALL];

        return new self(array_key_exists($key, self::VERSIONS) ? $key : self::OVERALL, max($versions));
    }

    /**
     * A template a response may claim to have been shown, or null.
     */
    public static function find(string $key, int $version): ?self
    {
        return in_array($version, self::VERSIONS[$key] ?? [], true) ? new self($key, $version) : null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::VERSIONS);
    }

    public function asksAboutAgent(): bool
    {
        return $this->key === self::STARTER;
    }

    /**
     * @return array{key: string, version: int}
     */
    public function toPayload(): array
    {
        return ['key' => $this->key, 'version' => $this->version];
    }
}
