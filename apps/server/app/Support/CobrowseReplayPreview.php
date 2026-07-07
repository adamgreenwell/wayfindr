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
    ];

    /**
     * Allowlisted inline-style properties for the replay preview. Layout, color,
     * and typography only; nothing that can take a url() or fetch a resource.
     * The server is the source of truth, so styling that is not on this list (or
     * whose value fails the safe-value grammar) is dropped regardless of what a
     * widget sends.
     *
     * @var list<string>
     */
    private const SAFE_STYLE_PROPERTIES = [
        'display', 'box-sizing', 'width', 'height', 'min-width', 'max-width',
        'min-height', 'max-height', 'margin', 'margin-top', 'margin-right',
        'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right',
        'padding-bottom', 'padding-left', 'border', 'border-width', 'border-style',
        'border-color', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-radius', 'box-shadow', 'flex-direction', 'flex-wrap', 'justify-content',
        'align-items', 'align-content', 'gap', 'row-gap', 'column-gap',
        'grid-template-columns', 'color',
        'background-color', 'background-image', 'opacity', 'visibility', 'font-family', 'font-size',
        'font-weight', 'font-style', 'line-height', 'text-align', 'text-decoration',
        'text-decoration-line', 'text-transform', 'white-space', 'letter-spacing',
        'word-spacing', 'vertical-align', 'list-style-type',
    ];

    /**
     * Function names allowed inside a style value (e.g. rgb(...)). Any other
     * function call, including url(), disqualifies the whole declaration. The
     * gradient family is safe because every function *inside* a gradient must
     * also be on this list, so stops are restricted to color functions — a
     * gradient smuggling url() or image-set() drops the whole declaration.
     *
     * @var list<string>
     */
    private const SAFE_STYLE_FUNCTIONS = [
        'rgb', 'rgba', 'hsl', 'hsla',
        'linear-gradient', 'radial-gradient', 'conic-gradient',
        'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient',
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
     * Visitor viewport widths outside this range are treated as unreported: a
     * hostile or broken widget must not be able to force an absurd preview
     * geometry onto the agent dashboard.
     */
    private const MIN_VIEWPORT_WIDTH = 320;

    private const MAX_VIEWPORT_WIDTH = 3840;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{srcdoc: string, applied_mutations: string, skipped_mutations: string, drift: array<string, mixed>, viewport_width: int|null}|null
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
            'unresolved' => 0,
            'unsupported' => 0,
            'invalid' => 0,
        ];

        $this->applyRecentMutations(
            $document,
            $metadata['mutations']['recent_batches'] ?? null,
            $counts,
            $this->snapshotMutationSequence($snapshot)
        );
        $this->sanitizeDocument($document);

        $skipped = $counts['unresolved'] + $counts['unsupported'] + $counts['invalid'];

        return [
            'srcdoc' => $this->wrapPreviewHtml($this->bodyHtml($document)),
            'applied_mutations' => number_format($counts['applied']).' applied',
            'skipped_mutations' => number_format($skipped).' skipped',
            'drift' => (new CobrowseReplayDrift)->evaluate($counts),
            'viewport_width' => self::reportedViewportWidth($metadata),
        ];
    }

    /**
     * The visitor's reported viewport width, so the preview can render at the
     * captured geometry instead of the dashboard column's. Uses the page-state
     * report (the latest known viewport); out-of-range values are unreported.
     * Public because the metadata-only broadcast carries the same clamped
     * value, letting the dashboard resize the preview without refetching it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function reportedViewportWidth(array $metadata): ?int
    {
        $width = $metadata['page_state']['viewport_width'] ?? null;

        if (! is_numeric($width)) {
            return null;
        }

        $width = (int) $width;

        return ($width >= self::MIN_VIEWPORT_WIDTH && $width <= self::MAX_VIEWPORT_WIDTH)
            ? $width
            : null;
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

        $this->maskSensitiveElements($document);
    }

    /**
     * Mask the text content of elements the widget marks sensitive
     * (data-secret / data-wayfindr-mask / data-wayfindr-private). The stock
     * widget masks these before sending, but the server is the source of truth:
     * an older or hostile widget could send them unmasked, and they must never
     * reach the agent replay preview in the clear.
     */
    private function maskSensitiveElements(DOMDocument $document): void
    {
        $matches = (new DOMXPath($document))->query('//*[@data-secret or @data-wayfindr-mask or @data-wayfindr-private]');

        if (! $matches) {
            return;
        }

        $nodes = [];

        foreach ($matches as $node) {
            $nodes[] = $node;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tagName = strtolower($node->tagName);

            if (in_array($tagName, ['input', 'textarea', 'select'], true)) {
                // For a sensitive form control, masking textContent is not enough
                // (an input serializes its value/placeholder attributes). Mirror
                // the widget and mask both so nothing reaches the agent in clear.
                if ($node->hasAttribute('value')) {
                    $node->setAttribute('value', '[masked]');
                }

                if ($node->hasAttribute('placeholder')) {
                    $node->setAttribute('placeholder', '[masked]');
                }

                if ($tagName !== 'input') {
                    $node->textContent = '[masked]';
                }

                continue;
            }

            $node->textContent = '[masked]';
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

            if ($normalizedName === 'style') {
                $safeStyle = $this->sanitizeStyleAttribute((string) $element->getAttribute($attributeName));

                if ($safeStyle === '') {
                    $element->removeAttribute($attributeName);
                } else {
                    $element->setAttribute('style', $safeStyle);
                }

                continue;
            }

            if (str_starts_with($normalizedName, 'on') || in_array($normalizedName, self::UNSAFE_ATTRIBUTE_NAMES, true)) {
                $element->removeAttribute($attributeName);
            }
        }
    }

    /**
     * Keep only allowlisted declarations whose values pass a conservative
     * safe-value grammar. Drops url(), @import, expression(), behaviors, markup
     * breakouts, and any function call other than the allowed color functions.
     */
    private function sanitizeStyleAttribute(string $style): string
    {
        $safe = [];

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($property === '' || $value === '' || ! in_array($property, self::SAFE_STYLE_PROPERTIES, true)) {
                continue;
            }

            if ($this->isSafeStyleValue($value, $property)) {
                $safe[] = $property.':'.$value;
            }
        }

        return implode(';', $safe);
    }

    /**
     * Per-property value length caps, aligned with the widget's capture caps:
     * gradients and multi-shadow lists legitimately run longer than the
     * conservative default, and a mismatch would make the widget serialize
     * surfaces the server then silently drops.
     *
     * @var array<string, int>
     */
    private const STYLE_VALUE_MAX_LENGTHS = [
        'background-image' => 500,
        'box-shadow' => 300,
    ];

    private function isSafeStyleValue(string $value, string $property = ''): bool
    {
        if (mb_strlen($value) > (self::STYLE_VALUE_MAX_LENGTHS[$property] ?? 256)) {
            return false;
        }

        $normalized = strtolower($value);

        foreach (['url(', '@import', 'expression(', 'javascript:', 'image-set(', '/*', '*/', '<', '>', '{', '}', '\\'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return false;
            }
        }

        // Any function call in the value must be an allowlisted color function.
        if (preg_match_all('/([a-z-]+)\s*\(/', $normalized, $matches)) {
            foreach ($matches[1] as $functionName) {
                if (! in_array($functionName, self::SAFE_STYLE_FUNCTIONS, true)) {
                    return false;
                }
            }
        }

        // Conservative character allowlist: alphanumerics and safe CSS value
        // punctuation (covers colors, lengths, keywords, quoted font names).
        return preg_match('/^[a-z0-9#%.,()\\/\\s_"\'-]+$/i', $value) === 1;
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
     * @param  array{applied: int, unresolved: int, unsupported: int, invalid: int}  $counts
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
                    $counts['invalid']++;

                    continue;
                }

                $counts[$this->applyMutation($document, $mutation)]++;
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
     * @return 'applied'|'unresolved'|'unsupported'|'invalid'
     */
    private function applyMutation(DOMDocument $document, array $mutation): string
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
            default => 'unsupported',
        };
    }

    /**
     * @return 'applied'|'unresolved'|'invalid'
     */
    private function applyTextMutation(DOMDocument $document, string $path, mixed $text): string
    {
        [$status, $element] = $this->resolvePath($document, $path);

        if ($status !== 'ok') {
            return $status;
        }

        if (! is_string($text)) {
            return 'invalid';
        }

        $element->textContent = $text;

        return 'applied';
    }

    /**
     * @return 'applied'|'unresolved'|'unsupported'|'invalid'
     */
    private function applyAttributeMutation(DOMDocument $document, string $path, mixed $name, mixed $value): string
    {
        [$status, $element] = $this->resolvePath($document, $path);

        if ($status !== 'ok') {
            return $status;
        }

        if (! is_string($name) || ! is_scalar($value)) {
            return 'invalid';
        }

        $attributeName = strtolower($name);

        if (! $this->isSafeMutationAttribute($attributeName)) {
            return 'unsupported';
        }

        $element->setAttribute($attributeName, (string) $value);

        return 'applied';
    }

    /**
     * @return 'applied'|'unresolved'|'invalid'
     */
    private function applyAddedMutation(DOMDocument $document, string $path, mixed $html): string
    {
        [$status, $parent] = $this->resolvePath($document, $path);

        if ($status !== 'ok') {
            return $status;
        }

        if (! is_string($html) || $html === '') {
            return 'invalid';
        }

        $fragmentDocument = $this->loadDocument($html);
        $this->sanitizeDocument($fragmentDocument);

        $body = $fragmentDocument->getElementsByTagName('body')->item(0);

        if (! $body) {
            return 'invalid';
        }

        $appended = false;

        foreach ($this->childNodes($body) as $child) {
            $parent->appendChild($document->importNode($child, true));
            $appended = true;
        }

        return $appended ? 'applied' : 'invalid';
    }

    private function isSafeMutationAttribute(string $attributeName): bool
    {
        if (! preg_match('/^[a-z0-9:-]+$/', $attributeName)) {
            return false;
        }

        return in_array($attributeName, self::SAFE_MUTATION_ATTRIBUTES, true)
            || str_starts_with($attributeName, 'aria-');
    }

    /**
     * Resolve a mutation path, distinguishing an unsupported path syntax from a
     * supported path that no longer matches a node. Only the latter is drift;
     * malformed or legacy paths are invalid and must not feed the drift ratio.
     *
     * @return array{0: 'ok'|'invalid'|'unresolved', 1: ?DOMElement}
     */
    private function resolvePath(DOMDocument $document, string $path): array
    {
        $xpath = $this->pathToXPath($path);

        if ($xpath === null) {
            return ['invalid', null];
        }

        $node = (new DOMXPath($document))->query($xpath)?->item(0);

        if (! $node instanceof DOMElement) {
            return ['unresolved', null];
        }

        return ['ok', $node];
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
