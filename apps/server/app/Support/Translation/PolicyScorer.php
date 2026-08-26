<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * Measures a draft against the policy on the way back in.
 *
 * The order of the pipeline is the argument for this class. A glossary can only
 * be *offered* to an engine, and an engine with a two-field request body cannot
 * take it -- so a run against Murf returned `Konversation` twenty-one times and
 * `Standort` eight, every one of them a term already ruled against. Nothing on
 * the way out can prevent that. Something on the way back can count it.
 *
 * It reports and does not gate. A term list matching on substrings will
 * eventually flag something legitimate, and a check that can fail a run is a
 * check people learn to bypass. What it produces is a number a reviewer can
 * read in a second: this draft broke the vocabulary thirty-five times, or it
 * did not.
 */
final class PolicyScorer
{
    public function __construct(private readonly Glossary $glossary) {}

    /**
     * @param  array<string, string>  $reviewed  the existing catalogue, when there is one
     */
    public function score(CataloguePlan $plan, array $reviewed = []): PolicyScore
    {
        $locale = $plan->targetLocale;
        $rejected = $this->glossary->rejected($locale);
        $checks = $this->glossary->checks($locale);

        $violations = [];
        $agreed = 0;

        // Violations are counted over everything the run would WRITE, drafted
        // and carried alike. That makes `--score` on a finished language a
        // self-audit -- does the shipped German obey the policy we wrote after
        // shipping it -- rather than a report on an empty draft.
        foreach ($plan->merged() as $key => $value) {
            foreach ($rejected as $term => $decided) {
                if (mb_stripos($value, (string) $term) !== false) {
                    $violations['rejected term'][] = [
                        'key' => $key,
                        'detail' => "'{$term}' -- {$decided}",
                    ];
                }
            }

            foreach ($checks as $rule => $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    $violations[$rule][] = [
                        'key' => $key,
                        'detail' => mb_strimwidth($value, 0, 90, '…'),
                    ];
                }
            }
        }

        // Agreement is counted over the DRAFTED keys only. A carried value
        // agrees with the reviewed catalogue by construction, and including it
        // would inflate the number with strings the engine never saw.
        foreach ($plan->translated as $key => $value) {
            if (($reviewed[$key] ?? null) === $value) {
                $agreed++;
            }
        }

        return new PolicyScore(
            catalogue: $plan->catalogue,
            scored: count($plan->merged()),
            drafted: count($plan->translated),
            violations: $violations,
            agreed: $reviewed === [] ? null : $agreed,
        );
    }
}
