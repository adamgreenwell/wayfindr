<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single operator-set configuration value that overrides env at runtime
 * (ADR 0011). Keyed by a stable setting key; the raw `value` is stored
 * encrypted for secret keys. Read and written through the OperatorSettings
 * service, which owns the registry of managed keys and their config mapping —
 * do not write arbitrary keys directly.
 */
#[Fillable([
    'key',
    'value',
])]
class OperatorSetting extends Model
{
    //
}
