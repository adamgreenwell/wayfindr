<?php

namespace App\Support\Knowledge;

/**
 * An article body, turned into blocks the widget can build as elements.
 *
 * The widget has never rendered server-authored markup. Message bodies are
 * assigned with `textContent`, and the intake form is built element by element
 * specifically so operator-configured labels cannot inject markup -- there is a
 * test asserting exactly that. Article bodies are the first content an operator
 * writes that a visitor's browser will lay out, on a page belonging to the
 * operator's own customer, so that posture has to hold here too.
 *
 * It holds by never producing HTML. Markdown in, a structured document out, and
 * the widget builds `<h3>`, `<p>`, `<ul>` and `<a>` from the block types it
 * recognises. A block type the widget does not know renders as nothing rather
 * than as markup, which is the failure direction worth having.
 *
 * The subset is deliberately small: headings, paragraphs, bullet lists, and
 * inline links, emphasis and code. A knowledge base needs headings to be
 * scannable and links to cross-reference itself; everything past that is
 * decoration a support article can do without.
 */
final class ArticleDocument
{
    /**
     * Schemes a link may use.
     *
     * The allowlist is the whole security boundary of this class. `javascript:`
     * is the obvious exclusion; `data:` is the one that gets forgotten, and it
     * carries a whole document rather than a destination.
     */
    private const SCHEMES = ['http', 'https', 'mailto'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function blocks(string $markdown): array
    {
        $blocks = [];
        $paragraph = [];
        $items = [];

        $flushParagraph = function () use (&$paragraph, &$blocks): void {
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'spans' => self::spans(implode(' ', $paragraph))];
                $paragraph = [];
            }
        };

        $flushList = function () use (&$items, &$blocks): void {
            if ($items !== []) {
                $blocks[] = ['type' => 'list', 'items' => array_map(self::spans(...), $items)];
                $items = [];
            }
        };

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();

                continue;
            }

            // One heading level. An article is a page, not a book, and offering
            // six levels invites a hierarchy nobody reading a support answer in
            // a 320px panel can perceive.
            if (preg_match('/^#{1,6}\s+(.*)$/', $trimmed, $match) === 1) {
                $flushParagraph();
                $flushList();
                $heading = trim($match[1]);

                if ($heading !== '') {
                    $blocks[] = ['type' => 'heading', 'text' => trim(self::plain($heading))];
                }

                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $match) === 1) {
                $flushParagraph();
                $item = trim($match[1]);

                if ($item !== '') {
                    $items[] = $item;
                }

                continue;
            }

            $flushList();
            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $flushList();

        return $blocks;
    }

    /**
     * The article as text, for search and for the plain-text quote an agent
     * inserts into a reply. Derived from the blocks rather than the source, so
     * a search never matches on syntax the reader never sees.
     */
    public static function text(string $markdown): string
    {
        $lines = [];

        foreach (self::blocks($markdown) as $block) {
            if ($block['type'] === 'heading') {
                $lines[] = $block['text'];

                continue;
            }

            if ($block['type'] === 'paragraph') {
                $lines[] = self::spansText($block['spans']);

                continue;
            }

            foreach ($block['items'] as $item) {
                $lines[] = self::spansText($item);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $spans
     */
    private static function spansText(array $spans): string
    {
        return implode('', array_map(static fn (array $span): string => $span['text'], $spans));
    }

    /**
     * Inline runs: a link, emphasis, code, or plain text.
     *
     * @return list<array<string, mixed>>
     */
    private static function spans(string $text): array
    {
        $spans = [];
        $offset = 0;

        // The destination run allows spaces on purpose: `[x](  javascript:… )`
        // has to REACH href() to be rejected there. A pattern that refuses it
        // first leaves the whole thing as literal text and the defence untested.
        $pattern = '/\[([^\]]+)\]\(([^)]*)\)|\*\*([^*]+)\*\*|`([^`]+)`/';

        while (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];

            if ($start > $offset) {
                $spans[] = ['text' => self::plain(substr($text, $offset, $start - $offset))];
            }

            if (($match[1][0] ?? '') !== '' && $match[1][1] !== -1) {
                $href = self::href($match[2][0]);
                $label = self::plain($match[1][0]);

                // A rejected destination keeps its words and loses its link. The
                // sentence still reads; it simply is not clickable.
                $spans[] = $href === null ? ['text' => $label] : ['text' => $label, 'href' => $href];
            } elseif (($match[3][1] ?? -1) !== -1) {
                $spans[] = ['text' => self::plain($match[3][0]), 'strong' => true];
            } else {
                $spans[] = ['text' => self::plain($match[4][0]), 'code' => true];
            }

            $offset = $start + strlen($match[0][0]);
        }

        if ($offset < strlen($text)) {
            $spans[] = ['text' => self::plain(substr($text, $offset))];
        }

        // A run that is only whitespace carries nothing, but one with padding
        // around real words keeps it -- that padding IS the word boundary.
        return array_values(array_filter($spans, static fn (array $span): bool => trim($span['text']) !== ''));
    }

    /**
     * A destination, or nothing.
     *
     * Parsed rather than pattern-matched, because the interesting attacks are
     * the ones that look like a scheme to a browser and not to a regex --
     * leading whitespace and control characters, mixed case, and the encoded
     * colon. `parse_url` answers what the scheme actually is.
     */
    private static function href(string $raw): ?string
    {
        $url = trim(preg_replace('/[\x00-\x20]/', '', $raw) ?? '');

        if ($url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return null;
        }

        return in_array(strtolower($scheme), self::SCHEMES, true) ? $url : null;
    }

    /**
     * Strip control characters, and nothing else.
     *
     * Deliberately does NOT trim. A span is a run inside a sentence, and
     * trimming each one welds its neighbours together: "within **14 days**"
     * became "within14 days" in both the rendered text and the search index,
     * so an article could not be found by a phrase it visibly contains.
     */
    private static function plain(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
    }
}
