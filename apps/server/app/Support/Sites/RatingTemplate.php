<?php

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteRatingTemplateVersion;

/**
 * The support-rating template a prompt asks with.
 *
 * Two built-ins (#1156) and a site's own published versions (#1157). A template
 * is identified by key AND version, and a response records both, so what an
 * answer means is fixed by what the visitor was shown rather than by whatever
 * the site is set to now. Neither kind of version is ever rewritten: a change
 * to a question's meaning is a new version.
 */
final class RatingTemplate
{
    /** The single overall question every site asked before templates existed. */
    public const OVERALL = 'overall';

    /** Overall experience, the named agent's help, and a comment. */
    public const STARTER = 'starter';

    /** A site's own published version. */
    public const CUSTOM = 'custom';

    /** @var array<string, list<int>> */
    private const VERSIONS = [
        self::OVERALL => [1],
        self::STARTER => [1],
    ];

    public const AGENT_SCORES = ['good', 'ok', 'bad', 'not_applicable'];

    private function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly ?SiteRatingTemplateVersion $published = null,
    ) {}

    /**
     * The template a site set to `$key` asks with now. A site set to its own
     * template with nothing published yet asks the single built-in question.
     */
    public static function current(string $key, ?Site $site = null): self
    {
        if ($key === self::CUSTOM && $site !== null) {
            $latest = SiteRatingTemplateVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();

            if ($latest !== null) {
                return new self(self::CUSTOM, (int) $latest->version, $latest);
            }
        }

        $versions = self::VERSIONS[$key] ?? self::VERSIONS[self::OVERALL];

        return new self(array_key_exists($key, self::VERSIONS) ? $key : self::OVERALL, max($versions));
    }

    /**
     * A template a response may claim to have been shown, or null.
     *
     * A custom version is looked up on the site the response belongs to, and
     * any version that was ever published qualifies -- not only the latest -- so
     * a visitor whose prompt opened before a new publish answers the question
     * they actually read.
     */
    public static function find(string $key, int $version, ?Site $site = null): ?self
    {
        if ($key === self::CUSTOM) {
            $published = $site === null ? null : SiteRatingTemplateVersion::query()
                ->where('site_id', $site->id)
                ->where('version', $version)
                ->first();

            return $published === null ? null : new self(self::CUSTOM, $version, $published);
        }

        return in_array($version, self::VERSIONS[$key] ?? [], true) ? new self($key, $version) : null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [...array_keys(self::VERSIONS), self::CUSTOM];
    }

    public function isCustom(): bool
    {
        return $this->published !== null;
    }

    public function definition(): ?RatingTemplateDefinition
    {
        return $this->published?->definition();
    }

    public function asksAboutAgent(): bool
    {
        return $this->isCustom() ? $this->definition()->asksAboutAgent() : $this->key === self::STARTER;
    }

    /**
     * `three` or `five`. Every built-in is three-choice.
     */
    public function scale(): string
    {
        return $this->isCustom() ? $this->definition()->scale() : 'three';
    }

    /**
     * @return list<string>
     */
    public function scores(): array
    {
        return RatingTemplateDefinition::scaleScores($this->scale());
    }

    /**
     * @return list<string>
     */
    public function agentScores(): array
    {
        return [...$this->scores(), 'not_applicable'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['key' => $this->key, 'version' => $this->version]
            + ($this->isCustom() ? ['definition' => $this->definition()->toWidgetPayload()] : []);
    }
}
