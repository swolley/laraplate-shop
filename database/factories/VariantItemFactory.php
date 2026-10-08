<?php

declare(strict_types=1);

namespace Modules\Shop\Database\Factories;

use Modules\Core\Overrides\Factory;
use Modules\ERP\Models\Item;
use Modules\Shop\Models\ProductVariant;
use Modules\Shop\Models\VariantItem;
use Override;

final class VariantItemFactory extends Factory
{
    /**
     * @var class-string<VariantItem>
     */
    #[Override]
    protected $model = VariantItem::class;

    /**
     * The sole sellable item of a simple variant.
     */
    public function main(): static
    {
        return $this->state(['role' => VariantItem::ROLE_MAIN]);
    }

    /**
     * A constituent of a bundle variant.
     */
    public function component(): static
    {
        return $this->state(['role' => VariantItem::ROLE_COMPONENT]);
    }

    /**
     * A `main` row of a fresh variant, pointing at a fresh ERP item. Use `->for($variant, 'variant')` and
     * `->for($item, 'item')` to compose an existing variant from existing items.
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function definitionsArray(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'item_id' => Item::factory(),
            'quantity' => '1.0000',
            'role' => VariantItem::ROLE_MAIN,
        ];
    }
}
