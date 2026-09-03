<?php

namespace App\Support\Api;

use Illuminate\Database\Eloquent\Model;

final readonly class IdempotentWrite
{
    public function __construct(
        public Model $resource,
        public bool $replayed,
    ) {}
}
