<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Explicit in-process input. The exact original stays in the owning composer. */
final readonly class DraftRefinementInput implements JsonSerializable
{
    public const MAX_CHARACTERS = 4000;

    public const TONES = ['calm', 'friendly', 'professional'];

    public const LANGUAGES = ['en', 'de', 'it'];

    public function __construct(
        #[SensitiveParameter] private string $draft,
        public string $draftRevision,
        public DraftRefinementAction $action,
        public ?string $tone = null,
        public ?string $language = null,
    ) {
        if (strlen($draft) > 16000 || ! mb_check_encoding($draft, 'UTF-8') || preg_match('/[^\s\p{Z}]/u', $draft) !== 1
            || mb_strlen($draft) > self::MAX_CHARACTERS || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $draft) === 1
            || ! Str::isUuid($draftRevision)) {
            throw new InvalidArgumentException('Invalid private refinement input.');
        }
        if (($action === DraftRefinementAction::Tone && ! in_array($tone, self::TONES, true))
            || ($action !== DraftRefinementAction::Tone && $tone !== null)
            || ($action === DraftRefinementAction::Translate && ! in_array($language, self::LANGUAGES, true))
            || ($action !== DraftRefinementAction::Translate && $language !== null)) {
            throw new InvalidArgumentException('Invalid refinement options.');
        }
    }

    /** Only the explicit builder may consume this original, before whole-input scrubbing. */
    public function draft(): string
    {
        return $this->draft;
    }

    public function operation(): array
    {
        return ['action' => $this->action->value, 'tone' => $this->tone, 'language' => $this->language];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Unsent refinement input cannot be implicitly serialized.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Queue only IDs for encrypted, expiring refinement input.');
    }

    public function __debugInfo(): array
    {
        return ['action' => $this->action->value, 'draft_characters' => mb_strlen($this->draft)];
    }
}
