<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/** @implements CastsAttributes<list<mixed>, list<mixed>> */
final readonly class OrderedJsonList implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<mixed>
     *
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnexpectedValueException("{$key} must be stored as a JSON list.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$key} must be an ordered list.");
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
