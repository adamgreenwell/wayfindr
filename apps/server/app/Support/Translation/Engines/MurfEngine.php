<?php

declare(strict_types=1);

namespace App\Support\Translation\Engines;

use App\Support\Translation\EngineBrief;
use App\Support\Translation\TranslationEngine;
use App\Support\Translation\TranslationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Murf's translation endpoint.
 *
 * `POST https://api.murf.ai/v1/text/translate`, body `{targetLanguage, texts}`,
 * billed on output characters. Ten texts per request and four thousand
 * characters each, per the capability documentation -- the API reference page
 * states no limits at all, and the two disagree, so the tighter number is used
 * and the batching is not something to tune without measuring against the real
 * service.
 *
 * **It cannot use the brief.** The request body has two fields; there is nowhere
 * to put a glossary, a register, or the catalogue's own notes. That is not a
 * criticism of the product -- the endpoint exists to prepare a script for
 * dubbing, where a paragraph of narration carries its own context -- but it is
 * the reason `usesBrief()` returns false and the reason the policy does not
 * make this the default engine. Protection tokens still hold, because they are
 * enforced on the way back rather than requested on the way out.
 */
final class MurfEngine implements TranslationEngine
{
    private const ENDPOINT = 'https://api.murf.ai/v1/text/translate';

    private const BATCH = 10;

    private const MAX_CHARS = 4000;

    /**
     * The catalogue speaks in bare language tags; Murf wants a region.
     *
     * Only the pairs actually reachable from `DashboardLanguage` and the
     * roadmap are listed. An unmapped locale is an error rather than a guess,
     * because guessing `de` into `de-DE` is right and guessing `pt` into
     * `pt-BR` is a decision about which Portuguese, made by accident.
     */
    private const LOCALES = [
        'de' => 'de-DE',
        'it' => 'it-IT',
        'fr' => 'fr-FR',
        'es' => 'es-ES',
        'nl' => 'nl-NL',
        'pl' => 'pl-PL',
    ];

    public function __construct(private readonly ?string $apiKey = null) {}

    public function name(): string
    {
        return 'murf';
    }

    public function usesBrief(): bool
    {
        return false;
    }

    public function translate(array $texts, EngineBrief $brief): array
    {
        if ($texts === []) {
            return [];
        }

        $key = $this->apiKey ?? config('services.murf.key');

        if (! is_string($key) || trim($key) === '') {
            throw new TranslationFailed('No Murf API key. Set MURF_API_KEY.');
        }

        $target = self::LOCALES[$brief->targetLocale] ?? null;

        if ($target === null) {
            throw new TranslationFailed(
                "Murf has no mapping for locale '{$brief->targetLocale}'. Add one to ".self::class.' rather than letting it be guessed.'
            );
        }

        foreach ($texts as $text) {
            if (mb_strlen($text) > self::MAX_CHARS) {
                throw new TranslationFailed(
                    'A string exceeds Murf\'s '.self::MAX_CHARS.'-character limit: '.mb_substr($text, 0, 60).'…'
                );
            }
        }

        $out = [];

        foreach (array_chunk($texts, self::BATCH) as $batch) {
            foreach ($this->translateBatch($batch, $key, $target) as $translated) {
                $out[] = $translated;
            }
        }

        if (count($out) !== count($texts)) {
            throw new TranslationFailed(
                'Murf returned '.count($out).' translations for '.count($texts).' inputs.'
            );
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $batch
     * @return array<int, string>
     */
    private function translateBatch(array $batch, string $key, string $target): array
    {
        try {
            $response = Http::withHeaders(['api-key' => $key])
                ->asJson()
                ->post(self::ENDPOINT, [
                    'targetLanguage' => $target,
                    'texts' => array_values($batch),
                ]);
        } catch (ConnectionException) {
            throw new TranslationFailed('Murf request failed before a response was received.');
        }

        if (! $response->successful()) {
            throw new TranslationFailed(
                'Murf returned '.$response->status().': '.mb_substr((string) $response->body(), 0, 200)
            );
        }

        $translations = $response->json('translations');

        if (! is_array($translations) || count($translations) !== count($batch)) {
            throw new TranslationFailed(
                'Murf returned a translations array that does not match the batch it was sent.'
            );
        }

        $out = [];

        foreach (array_values($batch) as $index => $source) {
            $entry = $translations[$index] ?? null;
            $translated = is_array($entry) ? ($entry['translated_text'] ?? null) : null;

            // The response echoes what it translated. Where it does, hold it to
            // that -- a reordered batch would otherwise pair every string with
            // the wrong translation and look entirely successful.
            $echoed = is_array($entry) ? ($entry['source_text'] ?? null) : null;

            if (is_string($echoed) && $echoed !== $source) {
                throw new TranslationFailed(
                    'Murf returned translations out of order: expected '.mb_substr($source, 0, 40).'…, got '.mb_substr($echoed, 0, 40).'…'
                );
            }

            if (! is_string($translated) || trim($translated) === '') {
                throw new TranslationFailed(
                    'Murf returned no translation for: '.mb_substr($source, 0, 60)
                );
            }

            $out[] = $translated;
        }

        return $out;
    }
}
