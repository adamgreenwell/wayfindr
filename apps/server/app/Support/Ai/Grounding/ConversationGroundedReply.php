<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

/**
 * A schema-checked model proposal, not a finding that its claims are true.
 * Cited sources are supplied article guidance, never authenticated transcript facts
 * or trusted instructions. The caller must reauthorize source access before use.
 */
final readonly class ConversationGroundedReply
{
    /**
     * @param  list<string>  $citations  Opaque references to supplied article passages.
     * @param  list<string>  $missingInformation  Model-declared information to clarify.
     * @param  list<array{ref:string,title:string,passage:string}>  $citedSources  Exact supplied source text remains untrusted.
     */
    public function __construct(
        public string $decision,
        public string $reply,
        public array $citations,
        public ?string $limitation,
        public array $missingInformation,
        public array $citedSources,
    ) {}
}
