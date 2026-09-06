<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

/** Stable, content-free reasons an evaluation candidate may hand off. */
enum GroundedAnswerRefusalReason: string
{
    case None = 'none';
    case LowConfidence = 'low_confidence';
    case Unsupported = 'unsupported';
    case ActionRequest = 'action_request';
    case SensitiveRequest = 'sensitive_request';
    case HighRisk = 'high_risk';
    case Policy = 'policy';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
