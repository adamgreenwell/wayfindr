<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

enum DraftRefinementAction: string
{
    case Clarify = 'clarify';
    case Shorten = 'shorten';
    case Tone = 'tone';
    case Translate = 'translate';
}
