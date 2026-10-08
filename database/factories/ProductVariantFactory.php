<?php

declare(strict_types=1);

namespace Modules\Shop\Database\Factories;

use Modules\Core\Overrides\Factory;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;
use Override;

final class ProductVariantFactory extends Factory
{
    /**
     * @var class-string<ProductVariant>
     */
    #[Override]
    protected $model = ProductVariant::class;

    /**
     * The variant that is the default of its product.
     */
    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    /**
     * A non-default variant of a fresh product. Use {@see self::default()} for the default one, and
     * `->for($product)` to attach the variant to an existing product.
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function definitionsArray(): array
    {
        return [
            'product_id' => Product::factory(),
            'is_default' => false,
            'attributes' => [
                'size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL']),
                'colour' => fake()->safeColorName(),
            ],
        ];
    }
}
