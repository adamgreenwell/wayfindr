<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

/** Build domain-separated identities for grounded-answer evaluation inputs. */
final class GroundedAnswerEvaluationIdentity
{
    private const SUITE_NAMESPACE = 'wayfindr:grounded-answer-evaluation:suite';

    private const PROMPT_CONTRACT_NAMESPACE = 'wayfindr:grounded-answer-evaluation:prompt-contract';

    /** @param array<string, mixed> $suite */
    public function suite(array $suite): string
    {
        return $this->digest(self::SUITE_NAMESPACE, $suite);
    }

    /** @param array<string, mixed> $promptContract */
    public function promptContract(array $promptContract): string
    {
        return $this->digest(self::PROMPT_CONTRACT_NAMESPACE, $promptContract);
    }

    /** @param array<string, mixed> $value */
    private function digest(string $namespace, array $value): string
    {
        $json = json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return 'sha256:'.hash('sha256', $namespace."\0".$json);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
