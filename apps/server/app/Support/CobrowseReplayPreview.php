<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class CobrowseReplayPreview
{
    /**
     * @var list<string>
     */
    private const UNSAFE_ELEMENT_NAMES = [
        'base',
        'canvas',
        'embed',
        'iframe',
        'link',
        'meta',
        'noscript',
        'object',
        'script',
        'style',
        'svg',
    ];

    /**
     * @var list<string>
     */
    private const UNSAFE_ATTRIBUTE_NAMES = [
        'action',
        'formaction',
        'href',
        'src',
        'srcdoc',
        'srcset',
        'style',
    ];

    /**
     * @var list<string>
     */
    private const SAFE_MUTATION_ATTRIBUTES = [
        'aria-current',
        'aria-expanded',
        'aria-hidden',
        'checked',
        'class',
        'disabled',
        'hidden',
        'selected',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{srcdoc: string, applied_mutations: string, skipped_mutations: string}|null
     */
    public function fromMetadata(array $metadata): ?array
    {
        $snapshot = $metadata['snapshot'] ?? null;

        if (! is_array($snapshot) || blank($snapshot['html'] ?? null)) {
            return null;
        }

        $document = $this->loadDocument((string) $snapshot['html']);
        $this->sanitizeDocument($document);

        $counts = [
            'applied' => 0,
            'skipped' => 0,
        ];

        $this->applyRecentMutations(
            $document,
            $metadata['mutations']['recent_batches'] ?? null,
            $counts,
            $this->snapshotMutationSequence($snapshot)
        );
        $this->sanitizeDocument($document);

        return [
            'srcdoc' => $this->wrapPreviewHtml($this->bodyHtml($document)),
            'applied_mutations' => number_format($counts['applied']).' applied',
            'skipped_mutations' => number_format($counts['skipped']).' skipped',
        ];
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function sanitizeDocument(DOMDocument $document): void
    {
        foreach (self::UNSAFE_ELEMENT_NAMES as $elementName) {
            $nodes = [];

            foreach ($document->getElementsByTagName($elementName) as $node) {
                $nodes[] = $node;
            }

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $elements = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            $elements[] = $element;
        }

        foreach ($elements as $element) {
            $this->sanitizeAttributes($element);
            $this->sanitizeFormControl($element);
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        $attributes = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[] = $attribute->nodeName;
        }

        foreach ($attributes as $attributeName) {
            $normalizedName = strtolower($attributeName);

            if (str_starts_with($normalizedName, 'on') || in_array($normalizedName, self::UNSAFE_ATTRIBUTE_NAMES, true)) {
                $element->removeAttribute($attributeName);
            }
        }
    }

    private function sanitizeFormControl(DOMElement $element): void
    {
        $tagName = strtolower($element->tagName);

        if ($tagName === 'input' && $element->hasAttribute('value')) {
            $element->setAttribute('value', '[masked]');

            return;
        }

        if (($tagName === 'textarea' || $tagName === 'select') && trim($element->textContent) !== '') {
            $element->textContent = '[masked]';
        }
    }

    /**
     * @param  array{applied: int, skipped: int}  $counts
     */
    private function applyRecentMutations(DOMDocument $document, mixed $batches, array &$counts, ?int $snapshotMutationSequence): void
    {
        if (! is_array($batches)) {
            return;
        }

        foreach ($batches as $batch) {
            if (! is_array($batch) || ! is_array($batch['mutations'] ?? null)) {
                continue;
            }

            if ($this->batchIsCoveredBySnapshot($batch, $snapshotMutationSequence)) {
                continue;
            }

            foreach ($batch['mutations'] as $mutation) {
                if (! is_array($mutation)) {
                    $counts['skipped']++;

                    continue;
                }

                $this->applyMutation($document, $mutation) ? $counts['applied']++ : $counts['skipped']++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotMutationSequence(array $snapshot): ?int
    {
        if (! is_numeric($snapshot['mutation_sequence'] ?? null)) {
            return null;
        }

        return (int) $snapshot['mutation_sequence'];
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    private function batchIsCoveredBySnapshot(array $batch, ?int $snapshotMutationSequence): bool
    {
        if ($snapshotMutationSequence === null || ! is_numeric($batch['sequence'] ?? null)) {
            return false;
        }

        return (int) $batch['sequence'] <= $snapshotMutationSequence;
    }

    /**
     * @param  array<string, mixed>  $mutation
     */
    private function applyMutation(DOMDocument $document, array $mutation): bool
    {
        $type = (string) ($mutation['type'] ?? '');
        $path = (string) ($mutation['path'] ?? '');

        return match ($type) {
            'text' => $this->applyTextMutation($document, $path, $mutation['text'] ?? null),
            'attribute' => $this->applyAttributeMutation(
                $document,
                $path,
                $mutation['attribute_name'] ?? null,
                $mutation['attribute_value'] ?? null,
            ),
            'added' => $this->applyAddedMutation($document, $path, $mutation['html'] ?? null),
            default => false,
        };
    }

    private function applyTextMutation(DOMDocument $document, string $path, mixed $text): bool
    {
        $element = $this->elementForPath($document, $path);

        if (! $element || ! is_string($text)) {
            return false;
        }

        $element->textContent = $text;

        return true;
    }

    private function applyAttributeMutation(DOMDocument $document, string $path, mixed $name, mixed $value): bool
    {
        $element = $this->elementForPath($document, $path);

        if (! $element || ! is_string($name) || ! is_scalar($value)) {
            return false;
        }

        $attributeName = strtolower($name);

        if (! $this->isSafeMutationAttribute($attributeName)) {
            return false;
        }

        $element->setAttribute($attributeName, (string) $value);

        return true;
    }

    private function applyAddedMutation(DOMDocument $document, string $path, mixed $html): bool
    {
        $parent = $this->elementForPath($document, $path);

        if (! $parent || ! is_string($html) || $html === '') {
            return false;
        }

        $fragmentDocument = $this->loadDocument($html);
        $this->sanitizeDocument($fragmentDocument);

        $body = $fragmentDocument->getElementsByTagName('body')->item(0);

        if (! $body) {
            return false;
        }

        $appended = false;

        foreach ($this->childNodes($body) as $child) {
            $parent->appendChild($document->importNode($child, true));
            $appended = true;
        }

        return $appended;
    }

    private function isSafeMutationAttribute(string $attributeName): bool
    {
        if (! preg_match('/^[a-z0-9:-]+$/', $attributeName)) {
            return false;
        }

        return in_array($attributeName, self::SAFE_MUTATION_ATTRIBUTES, true)
            || str_starts_with($attributeName, 'aria-');
    }

    private function elementForPath(DOMDocument $document, string $path): ?DOMElement
    {
        $xpath = $this->pathToXPath($path);

        if ($xpath === null) {
            return null;
        }

        $node = (new DOMXPath($document))->query($xpath)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function pathToXPath(string $path): ?string
    {
        $segments = array_map('trim', explode('>', $path));
        $xpath = '';

        foreach ($segments as $segment) {
            if (! preg_match('/^([a-z][a-z0-9-]*):nth-of-type\(([1-9][0-9]*)\)$/', strtolower($segment), $matches)) {
                return null;
            }

            $tagName = $matches[1];
            $index = (int) $matches[2];

            if ($tagName === 'body') {
                if ($index !== 1 || $xpath !== '') {
                    return null;
                }

                $xpath = '/html/body';

                continue;
            }

            if ($xpath === '') {
                return null;
            }

            $xpath .= sprintf(
                '/*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "%s" and count(preceding-sibling::*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "%s"]) = %d]',
                $tagName,
                $tagName,
                $index - 1
            );
        }

        return $xpath !== '' ? $xpath : null;
    }

    private function bodyHtml(DOMDocument $document): string
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body) {
            return '';
        }

        return collect($this->childNodes($body))
            ->map(fn (DOMNode $node): string => $document->saveHTML($node) ?: '')
            ->implode('');
    }

    /**
     * @return list<DOMNode>
     */
    private function childNodes(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }

    private function wrapPreviewHtml(string $bodyHtml): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'html{color-scheme:light;}'
            .'body{margin:0;padding:16px;background:#fff;color:#1d2523;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.5;pointer-events:none;}'
            .'*{box-sizing:border-box;max-width:100%;}'
            .'[hidden]{display:none!important;}'
            .'input,button,select,textarea{font:inherit;}'
            .'</style></head><body>'.$bodyHtml.'</body></html>';
    }
}
