<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

/**
 * A host model factory, to prove the documented `afterCreating` tagging recipe.
 *
 * @extends Factory<Article>
 */
final class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['title' => $this->faker->sentence(3)];
    }
}
