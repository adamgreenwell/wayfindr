<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

use RuntimeException;

/** Bind local scoring and input-validation code without depending on checkout paths. */
final class GroundedAnswerScoringContract
{
    private const VERSION = 1;

    private const SOURCE_NAMESPACE = 'wayfindr:grounded-answer-evaluation:scoring-source:v1';

    /** @var array<string, string> */
    private const IMPLEMENTATIONS = [
        'dataset_loader' => 'GroundedAnswerEvaluationDatasetLoader.php',
        'evaluator' => 'GroundedAnswerEvaluator.php',
        'language_matcher' => 'GroundedAnswerLanguageMatcher.php',
        'phrase_matcher' => 'GroundedAnswerPhraseMatcher.php',
        'refusal_reason' => 'GroundedAnswerRefusalReason.php',
    ];

    /** @return array{version: int, implementations: array<string, string>} */
    public function contract(): array
    {
        $implementations = [];

        foreach (self::IMPLEMENTATIONS as $name => $file) {
            $source = @file_get_contents(__DIR__.'/'.$file);

            if ($source === false) {
                throw new RuntimeException('The evaluation scoring implementation could not be identified.');
            }

            $implementations[$name] = $this->sourceFingerprint($source);
        }

        return [
            'version' => self::VERSION,
            'implementations' => $implementations,
        ];
    }

    /** Fingerprint significant PHP tokens while preserving token and string boundaries. */
    public function sourceFingerprint(string $source): string
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $text = in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)
                    ? rtrim($token[1])
                    : $token[1];
                $tokens[] = [token_name($token[0]), $text];
            } else {
                $tokens[] = $token;
            }
        }

        $json = json_encode($tokens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'sha256:'.hash('sha256', self::SOURCE_NAMESPACE."\0".$json);
    }
}
