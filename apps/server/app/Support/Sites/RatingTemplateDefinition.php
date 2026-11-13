<?php

namespace App\Support\Sites;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * What a customized rating template asks, and how its answers are read (#1157).
 *
 * The one place a definition is validated, normalised and interpreted, so the
 * editor, the widget payload, the write path and the reports cannot disagree
 * about what an answer means.
 *
 * Deliberately NOT editable: which answers count as positive. That is fixed by
 * the scale -- `good` on the three-choice scale, 4 and 5 on the five-star scale
 * -- because a positive share that each site defines for itself is not a figure
 * anybody can read, and one that changes between versions silently rewrites
 * history. Wording, labels, which optional questions are asked and the scale are
 * the site's to choose; the arithmetic is not.
 *
 * Text is plain text. It is stored as typed, escaped wherever it is rendered,
 * and never interpreted as markup.
 */
final class RatingTemplateDefinition
{
    public const SCALES = ['three', 'five'];

    /** The visitor-facing languages a template can be written in. English is the fallback. */
    public const LOCALES = ['en', 'de', 'it'];

    public const TEXT_MAX = 200;

    public const LABEL_MAX = 40;

    private const CHOICES = [
        'three' => ['good', 'ok', 'bad'],
        'five' => ['1', '2', '3', '4', '5'],
    ];

    private const POSITIVE = [
        'three' => ['good'],
        'five' => ['4', '5'],
    ];

    /**
     * @param  array<string, mixed>  $definition  already normalised
     */
    private function __construct(private readonly array $definition) {}

    /**
     * The starting point for a site's first draft: the built-in starter
     * template, word for word, so an admin edits from something that works.
     */
    public static function starter(): self
    {
        return new self([
            'scale' => 'three',
            'overall' => ['text' => [
                'en' => 'How was your overall support experience?',
                'de' => 'Wie war Ihre Support-Erfahrung insgesamt?',
            ]],
            'agent' => ['enabled' => true, 'text' => [
                'en' => 'How was the help you received from {name}?',
                'de' => 'Wie war die Hilfe von {name}?',
            ]],
            'comment' => ['enabled' => true, 'text' => [
                'en' => 'Anything you would like us to know? (optional)',
                'de' => 'Möchten Sie uns noch etwas mitteilen? (optional)',
            ]],
            'labels' => [
                'good' => ['en' => 'Good', 'de' => 'Gut'],
                'ok' => ['en' => 'Okay', 'de' => 'Ganz okay'],
                'bad' => ['en' => 'Badly', 'de' => 'Schlecht'],
                '1' => ['en' => '1 star'],
                '2' => ['en' => '2 stars'],
                '3' => ['en' => '3 stars'],
                '4' => ['en' => '4 stars'],
                '5' => ['en' => '5 stars'],
                'not_applicable' => ['en' => 'Not applicable', 'de' => 'Trifft nicht zu'],
            ],
        ]);
    }

    /**
     * A stored definition, trusted as already validated when it was saved.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): self
    {
        return is_array($stored) ? new self(self::normalise($stored)) : self::starter();
    }

    /**
     * Validate and normalise editor input, or throw naming the field.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $definition = self::normalise($input);
        $errors = [];

        if (! in_array($definition['scale'], self::SCALES, true)) {
            $errors['scale'] = 'Choose one of the supported scales.';
        }

        // English is the fallback every other language lands on, so each
        // question that is asked, and each answer that can be chosen, needs it.
        foreach (['overall', 'agent', 'comment'] as $question) {
            if ($question !== 'overall' && ! $definition[$question]['enabled']) {
                continue;
            }

            if (($definition[$question]['text']['en'] ?? '') === '') {
                $errors[$question.'.text.en'] = 'Write the English wording; other languages fall back to it.';
            }
        }

        foreach ($definition['agent']['enabled'] ? $definition['agent']['text'] : [] as $locale => $text) {
            if (! str_contains($text, '{name}')) {
                $errors['agent.text.'.$locale] = 'Include {name} where the agent’s name goes, so the visitor knows whose help they are rating.';
            }
        }

        foreach (self::choicesFor($definition['scale'], $definition['agent']['enabled']) as $choice) {
            if (($definition['labels'][$choice]['en'] ?? '') === '') {
                $errors['labels.'.$choice.'.en'] = 'Give every answer an English label.';
            }
        }

        foreach (Arr::dot($definition) as $path => $value) {
            if (! is_string($value)) {
                continue;
            }

            $max = str_starts_with($path, 'labels.') ? self::LABEL_MAX : self::TEXT_MAX;

            if (mb_strlen($value) > $max) {
                $errors[$path] = "Keep this to {$max} characters.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($definition);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalise(array $input): array
    {
        $texts = function (mixed $values): array {
            $out = [];

            foreach (self::LOCALES as $locale) {
                $value = is_array($values) && is_string($values[$locale] ?? null) ? trim($values[$locale]) : '';

                if ($value !== '') {
                    $out[$locale] = $value;
                }
            }

            return $out;
        };

        $labels = [];

        foreach ([...self::CHOICES['three'], ...self::CHOICES['five'], 'not_applicable'] as $choice) {
            $labels[$choice] = $texts($input['labels'][$choice] ?? null);
        }

        return [
            'scale' => is_string($input['scale'] ?? null) ? $input['scale'] : 'three',
            'overall' => ['text' => $texts($input['overall']['text'] ?? null)],
            'agent' => [
                'enabled' => filter_var($input['agent']['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'text' => $texts($input['agent']['text'] ?? null),
            ],
            'comment' => [
                'enabled' => filter_var($input['comment']['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'text' => $texts($input['comment']['text'] ?? null),
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return list<string>
     */
    private static function choicesFor(string $scale, bool $agent): array
    {
        return [...(self::CHOICES[$scale] ?? self::CHOICES['three']), ...($agent ? ['not_applicable'] : [])];
    }

    public function scale(): string
    {
        return $this->definition['scale'];
    }

    public function asksAboutAgent(): bool
    {
        return $this->definition['agent']['enabled'];
    }

    public function asksForComment(): bool
    {
        return $this->definition['comment']['enabled'];
    }

    /**
     * @return list<string>
     */
    public function scores(): array
    {
        return self::CHOICES[$this->scale()];
    }

    /**
     * @return list<string>
     */
    public function agentScores(): array
    {
        return [...$this->scores(), 'not_applicable'];
    }

    /**
     * @return list<string>
     */
    public static function positiveScores(string $scale): array
    {
        return self::POSITIVE[$scale] ?? self::POSITIVE['three'];
    }

    /**
     * @return list<string>
     */
    public static function scaleScores(string $scale): array
    {
        return self::CHOICES[$scale] ?? self::CHOICES['three'];
    }

    /**
     * The wording in `$locale`, falling back to English.
     */
    public function text(string $question, string $locale = 'en'): string
    {
        $texts = $this->definition[$question]['text'] ?? [];

        return $texts[$locale] ?? $texts['en'] ?? '';
    }

    public function label(string $choice, string $locale = 'en'): string
    {
        $labels = $this->definition['labels'][$choice] ?? [];

        return $labels[$locale] ?? $labels['en'] ?? $choice;
    }

    /**
     * Everything the widget needs, in every language the site wrote, so it can
     * pick the visitor's own and fall back to English itself.
     *
     * @return array<string, mixed>
     */
    public function toWidgetPayload(): array
    {
        $labels = [];

        foreach ($this->agentScores() as $choice) {
            $labels[$choice] = $this->definition['labels'][$choice];
        }

        return [
            'scale' => $this->scale(),
            'scores' => $this->scores(),
            'overall' => $this->definition['overall']['text'],
            'agent' => $this->asksAboutAgent() ? $this->definition['agent']['text'] : null,
            'comment' => $this->asksForComment() ? $this->definition['comment']['text'] : null,
            'labels' => $labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }
}
