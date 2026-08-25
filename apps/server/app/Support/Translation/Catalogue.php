<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * One `lang/<locale>/<name>.php` file, read and written.
 *
 * **It will not rewrite a catalogue that already exists**, and that is the
 * central decision rather than a limitation. The German files carry
 * twenty-seven comments inside the array and the English ones forty-six --
 * `conversations.php` explains its own plural rule where a reader will meet it,
 * `nav.php` explains why the product name is absent. Regenerating a file to
 * insert four missing keys would silently delete all of that, and the deletion
 * would look exactly like a successful run.
 *
 * So a new locale gets written whole, and an existing one gets its missing keys
 * emitted beside it for a person to place. The second is less convenient and is
 * the only version that cannot destroy work.
 */
final class Catalogue
{
    /**
     * @param  array<string, mixed>  $tree
     */
    private function __construct(
        public readonly string $name,
        public readonly string $docblock,
        private readonly array $tree,
    ) {}

    public static function read(string $path): self
    {
        if (! is_file($path)) {
            throw new TranslationFailed("No catalogue at {$path}.");
        }

        $tree = require $path;

        if (! is_array($tree)) {
            throw new TranslationFailed("The catalogue at {$path} did not return an array.");
        }

        return new self(
            basename($path, '.php'),
            self::extractDocblock((string) file_get_contents($path)),
            $tree,
        );
    }

    /**
     * The leading comment: the file's own notes to whoever translates it.
     */
    private static function extractDocblock(string $source): string
    {
        if (preg_match('/^<\?php\s*(?:declare\(strict_types=1\);\s*)?(\/\*.*?\*\/)\s*return/s', $source, $m) !== 1) {
            return '';
        }

        $lines = array_map(
            static fn (string $line): string => preg_replace('/^\s*\*ate?\s?|^\s*\*\s?|^\s*\/\*+\s?|\s*\*\/\s*$/', '', $line) ?? $line,
            explode("\n", $m[1]),
        );

        return trim(implode("\n", $lines));
    }

    /**
     * Dot-notation leaves, in file order.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $out = [];

        $walk = function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if (is_array($value)) {
                    $walk($value, $path);

                    continue;
                }

                if (is_string($value)) {
                    $out[$path] = $value;
                }
            }
        };

        $walk($this->tree, '');

        return $out;
    }

    /**
     * Rebuild a nested tree from dot-notation pairs, keeping the given order.
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function nest(array $values): array
    {
        $tree = [];

        foreach ($values as $path => $value) {
            $node = &$tree;

            foreach (explode('.', $path) as $segment) {
                if (! isset($node[$segment]) || ! is_array($node[$segment])) {
                    $node[$segment] = [];
                }

                $node = &$node[$segment];
            }

            $node = $value;
            unset($node);
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    public static function render(array $tree, string $header): string
    {
        $out = "<?php\n\n";

        if (trim($header) !== '') {
            $out .= "/*\n";

            foreach (explode("\n", trim($header)) as $line) {
                $out .= rtrim(' * '.$line)."\n";
            }

            $out .= " */\n";
        }

        $out .= "return [\n";
        $out .= self::renderArray($tree, 1);

        return $out."];\n";
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function renderArray(array $node, int $depth): string
    {
        $pad = str_repeat('    ', $depth);
        $out = '';

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $out .= $pad.self::quote((string) $key)." => [\n";
                $out .= self::renderArray($value, $depth + 1);
                $out .= $pad."],\n";

                continue;
            }

            $out .= $pad.self::quote((string) $key).' => '.self::quote((string) $value).",\n";
        }

        return $out;
    }

    /**
     * A single-quoted PHP literal.
     *
     * Only `\` and `'` are escapable inside single quotes, which is exactly the
     * trap that once shipped `unbeantwortet\"` to a German agent: a `\"` written
     * by habit stays a backslash and a quote. Escaping precisely these two and
     * nothing else is what keeps the value that comes back out of `require`
     * identical to the value that went in.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
