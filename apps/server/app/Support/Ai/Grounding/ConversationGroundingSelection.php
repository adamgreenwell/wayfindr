<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** In-process selected guidance. Only providerSources() crosses the eventual provider boundary. */
final readonly class ConversationGroundingSelection implements JsonSerializable
{
    /** @param list<array{ref: string, title: string, passage: string}> $sources
     * @param  array<string, mixed>  $snapshot  Server-only, content-free source identity.
     */
    public function __construct(
        public string $state,
        #[SensitiveParameter] private array $sources,
        private array $snapshot,
    ) {
        if (! in_array($state, ['disabled', 'no_sources', 'selected'], true)
            || ! array_is_list($sources) || count($sources) > 3
            || ($state === 'selected') !== ($sources !== [])) {
            throw new InvalidArgumentException('Invalid grounding selection.');
        }
        $seen = [];
        foreach ($sources as $source) {
            if (! is_array($source) || array_keys($source) !== ['ref', 'title', 'passage']
                || ! is_string($source['ref']) || preg_match('/\Asrc-[a-f0-9]{24}\z/', $source['ref']) !== 1
                || ! is_string($source['title']) || ! is_string($source['passage']) || trim($source['passage']) === ''
                || isset($seen[$source['ref']])) {
                throw new InvalidArgumentException('Invalid selected grounding source.');
            }
            $seen[$source['ref']] = true;
        }
    }

    /** @return list<array{ref: string, title: string, passage: string}> */
    public function providerSources(): array
    {
        return $this->sources;
    }

    /** @return array<string, mixed> Server-only, content-free identity; never provider input. */
    public function sourceSnapshot(): array
    {
        return $this->snapshot;
    }

    /** @return list<string> */
    public function references(): array
    {
        return array_column($this->sources, 'ref');
    }

    public function encodedCharacters(): int
    {
        return mb_strlen(json_encode($this->sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Grounding selections cannot be serialized; explicitly select providerSources().');
    }

    public function __serialize(): never
    {
        throw new LogicException('Grounding selections cannot be queued; queue record IDs and source references only.');
    }

    public function __debugInfo(): array
    {
        return ['state' => $this->state, 'source_count' => count($this->sources), 'encoded_characters' => $this->encodedCharacters()];
    }
}
