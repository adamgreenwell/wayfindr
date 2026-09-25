<?php

namespace App\Support\Mail;

/**
 * Whether mail this install hands to its transport can actually leave it.
 *
 * One answer, used by the operator's send-test and by anything that promises a
 * person an email. `log`, `array` and `null` accept every message and deliver
 * none, so a feature that only checked for a configured mailer would tell an
 * agent "emailed" about mail that went to a log file.
 */
final class OutboundMail
{
    /** An ordinary transport with no sink fallback. */
    public const DELIVERABLE = 'deliverable';

    /** A real transport is attempted, but a silent fallback to a sink is possible. */
    public const MAY_FALL_BACK = 'may_fall_back';

    /** The message cannot leave the server. */
    public const NON_DELIVERING = 'non_delivering';

    /**
     * Whether a message sent now is at least attempted over a real transport.
     *
     * `may_fall_back` counts: a real transport is tried first, which is the
     * same promise the send-test makes with a warning attached.
     */
    public function delivers(): bool
    {
        return $this->assess((string) config('mail.default')) !== self::NON_DELIVERING;
    }

    public function assess(string $mailer): string
    {
        return $this->assessMailer(strtolower($mailer), []);
    }

    /**
     * Recursively assess how honestly a send can claim delivery for a mailer,
     * accounting for composite ORDER (a flat leaf list can't):
     *  - 'non_delivering': the message can't leave the server — a leaf log/array/
     *    null, a composite of only sinks, OR a failover whose first reliably-
     *    succeeding transport is a local sink. Laravel's failover tries members
     *    in order and stops at the first success; array/log always succeed, so a
     *    chain like [array, smtp] never reaches smtp.
     *  - 'may_fall_back': a real transport is attempted but a silent fallback to
     *    a sink is possible — a failover with a real transport BEFORE a sink, or
     *    a roundrobin (random per-send pick) that might land on a sink.
     *  - 'deliverable': an ordinary transport with no sink fallback.
     *
     * @param  list<string>  $visited  composite mailer names already on this path
     */
    private function assessMailer(string $mailer, array $visited): string
    {
        $transport = strtolower((string) config("mail.mailers.{$mailer}.transport", $mailer));

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return in_array($transport, ['', 'log', 'array', 'null'], true) ? self::NON_DELIVERING : self::DELIVERABLE;
        }

        // A self-referential composite can't be resolved further; treat it as an
        // opaque real transport rather than looping (a genuine send would error
        // and be caught).
        if (in_array($mailer, $visited, true)) {
            return self::DELIVERABLE;
        }

        $visited[] = $mailer;

        $members = array_values(array_filter(
            (array) config("mail.mailers.{$mailer}.mailers", []),
            'is_string',
        ));

        if ($members === []) {
            return self::DELIVERABLE;
        }

        $assessments = array_map(fn (string $member): string => $this->assessMailer(strtolower($member), $visited), $members);

        if ($transport === 'roundrobin') {
            // Random pick per send: a sink anywhere means it might not deliver.
            if (! in_array(self::DELIVERABLE, $assessments, true) && ! in_array(self::MAY_FALL_BACK, $assessments, true)) {
                return self::NON_DELIVERING; // every member is a guaranteed sink
            }

            return in_array(self::NON_DELIVERING, $assessments, true) || in_array(self::MAY_FALL_BACK, $assessments, true)
                ? self::MAY_FALL_BACK
                : self::DELIVERABLE;
        }

        // failover: tried in order, stops at the first success. A sink always
        // succeeds, so the first sink is terminal — everything after it is dead.
        $realChanceSeen = false;
        $anyMayFallBack = false;

        foreach ($assessments as $assessment) {
            if ($assessment === self::NON_DELIVERING) {
                // First reliably-succeeding transport is a sink; nothing later runs.
                return $realChanceSeen ? self::MAY_FALL_BACK : self::NON_DELIVERING;
            }

            $realChanceSeen = true;
            $anyMayFallBack = $anyMayFallBack || $assessment === self::MAY_FALL_BACK;
        }

        return $anyMayFallBack ? self::MAY_FALL_BACK : self::DELIVERABLE;
    }
}
