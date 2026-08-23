<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = rtrim($this->faker->unique()->sentence(4), '.');

        return [
            'account_id' => Account::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'body' => "## {$title}\n\n".$this->faker->paragraph(),
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->subMinute()]);
    }
}
