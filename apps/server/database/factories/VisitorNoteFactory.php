<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Visitor;
use App\Models\VisitorNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisitorNote> */
final class VisitorNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'account_id' => fn (array $attributes): int => (int) Visitor::query()
                ->with('site')
                ->findOrFail($attributes['visitor_id'])
                ->site
                ->account_id,
            'author_id' => null,
            'body' => fake()->sentence(),
        ];
    }
}
