<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\ERP\Models\Item;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;
use Modules\Shop\Models\VariantItem;

beforeEach(function (): void {
    setupShopEntities();
});

it('soft-deleting a product soft-deletes its variants and their composition rows', function (): void {
    $product = Product::factory()->create(['kind' => ProductKind::Physical]);
    $default = ProductVariant::factory()->default()->for($product)->create();
    $second = ProductVariant::factory()->for($product)->create();
    $rows = [
        VariantItem::factory()->main()->for($default, 'variant')->create(),
        VariantItem::factory()->component()->for($second, 'variant')->create(),
    ];
    $row_ids = array_map(static fn (VariantItem $row): int => $row->getKey(), $rows);

    $other_variant = ProductVariant::factory()->create();
    $other_row = VariantItem::factory()->main()->for($other_variant, 'variant')->create();

    $product->delete();

    expect(ProductVariant::query()->where('product_id', $product->getKey())->count())->toBe(0)
        ->and(VariantItem::query()->whereIn('id', $row_ids)->count())->toBe(0)
        ->and(ProductVariant::withTrashed()->where('product_id', $product->getKey())->count())->toBe(2)
        ->and(VariantItem::withTrashed()->whereIn('id', $row_ids)->count())->toBe(2)
        // Another product's subtree is untouched.
        ->and(ProductVariant::query()->whereKey($other_variant->getKey())->exists())->toBeTrue()
        ->and(VariantItem::query()->whereKey($other_row->getKey())->exists())->toBeTrue();
});

it('restoring a product restores exactly the variants and rows its delete cascaded', function (): void {
    $product = Product::factory()->create(['kind' => ProductKind::Physical]);
    $default = ProductVariant::factory()->default()->for($product)->create();
    $second = ProductVariant::factory()->for($product)->create();
    $default_row = VariantItem::factory()->main()->for($default, 'variant')->create();
    $second_row = VariantItem::factory()->component()->for($second, 'variant')->create();

    $product->delete();

    expect($product->variants()->count())->toBe(0);

    Product::withTrashed()->findOrFail($product->getKey())->restore();

    expect(Product::query()->whereKey($product->getKey())->exists())->toBeTrue()
        ->and($product->variants()->pluck('id')->sort()->values()->all())->toBe([$default->getKey(), $second->getKey()])
        ->and(VariantItem::query()->whereIn('id', [$default_row->getKey(), $second_row->getKey()])->count())->toBe(2)
        ->and($product->refresh()->defaultVariant->is($default))->toBeTrue()
        ->and($default->refresh()->items->first()->is($default_row))->toBeTrue();
});

it('does not resurrect a variant or row removed on its own before the product was deleted', function (): void {
    $product = Product::factory()->create(['kind' => ProductKind::Physical]);
    $kept = ProductVariant::factory()->default()->for($product)->create();
    $kept_row = VariantItem::factory()->main()->for($kept, 'variant')->create();
    $removed_row = VariantItem::factory()->component()->for($kept, 'variant')->create();
    $removed = ProductVariant::factory()->for($product)->create();
    $removed_variant_row = VariantItem::factory()->component()->for($removed, 'variant')->create();

    $removed_row->delete();
    $removed->delete();

    // The product is deleted later, so its cascade carries a different timestamp than the earlier deletes.
    $this->travel(1)->minutes();
    $product->delete();

    $this->travel(1)->minutes();
    Product::withTrashed()->findOrFail($product->getKey())->restore();

    expect(ProductVariant::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(VariantItem::query()->whereKey($kept_row->getKey())->exists())->toBeTrue()
        ->and(VariantItem::query()->whereKey($removed_row->getKey())->exists())->toBeFalse()
        ->and(ProductVariant::query()->whereKey($removed->getKey())->exists())->toBeFalse()
        ->and(VariantItem::query()->whereKey($removed_variant_row->getKey())->exists())->toBeFalse()
        ->and(ProductVariant::withTrashed()->whereKey($removed->getKey())->exists())->toBeTrue()
        ->and(VariantItem::withTrashed()->whereKey($removed_variant_row->getKey())->exists())->toBeTrue();

    // The independently removed variant restores on its own once its product is live, and brings back
    // the row its own delete cascaded: the product cascade did not touch rows of an already trashed variant.
    ProductVariant::withTrashed()->findOrFail($removed->getKey())->restore();

    expect(ProductVariant::query()->whereKey($removed->getKey())->exists())->toBeTrue()
        ->and(VariantItem::query()->whereKey($removed_variant_row->getKey())->exists())->toBeTrue()
        ->and(VariantItem::query()->whereKey($removed_row->getKey())->exists())->toBeFalse();
});

it('soft-deleting a variant cascades to its rows and restoring it brings back only those', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $first = VariantItem::factory()->component()->for($variant, 'variant')->create();
    $second = VariantItem::factory()->component()->for($variant, 'variant')->create();
    $removed = VariantItem::factory()->component()->for($variant, 'variant')->create();
    $removed->delete();

    $other_row = VariantItem::factory()->main()->for(ProductVariant::factory()->create(), 'variant')->create();

    $this->travel(1)->minutes();
    $variant->delete();

    expect(VariantItem::query()->where('variant_id', $variant->getKey())->count())->toBe(0)
        ->and(VariantItem::withTrashed()->where('variant_id', $variant->getKey())->count())->toBe(3)
        ->and(VariantItem::query()->whereKey($other_row->getKey())->exists())->toBeTrue();

    $this->travel(1)->minutes();
    ProductVariant::withTrashed()->findOrFail($variant->getKey())->restore();

    expect(VariantItem::query()->where('variant_id', $variant->getKey())->pluck('id')->sort()->values()->all())
        ->toBe([$first->getKey(), $second->getKey()])
        ->and(VariantItem::query()->whereKey($removed->getKey())->exists())->toBeFalse();
});

it('refuses to restore a variant while its product is trashed', function (): void {
    $product = Product::factory()->create(['kind' => ProductKind::Physical]);
    $variant = ProductVariant::factory()->default()->for($product)->create();
    $row = VariantItem::factory()->main()->for($variant, 'variant')->create();

    $product->delete();

    expect(fn () => ProductVariant::withTrashed()->findOrFail($variant->getKey())->restore())
        ->toThrow(ValidationException::class);

    expect(ProductVariant::query()->whereKey($variant->getKey())->exists())->toBeFalse()
        ->and(ProductVariant::withTrashed()->whereKey($variant->getKey())->exists())->toBeTrue();

    // The product's own restore is the way back, and it revives both levels.
    Product::withTrashed()->findOrFail($product->getKey())->restore();

    expect(ProductVariant::query()->whereKey($variant->getKey())->exists())->toBeTrue()
        ->and(VariantItem::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

it('refuses to restore a composition row while its variant is trashed, so a restore cannot recreate a duplicate', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $item = Item::factory()->create();
    $earlier = VariantItem::factory()->component()->for($variant, 'variant')->for($item, 'item')->create();
    $earlier->delete();
    $live = VariantItem::factory()->component()->for($variant, 'variant')->for($item, 'item')->create();

    $this->travel(1)->minutes();
    $variant->delete();

    // The sibling is trashed with the variant, so without the parent check the duplicate guard would let
    // this restore through and the variant's restore would then revive both rows.
    expect(fn () => VariantItem::withTrashed()->findOrFail($earlier->getKey())->restore())
        ->toThrow(ValidationException::class);

    ProductVariant::withTrashed()->findOrFail($variant->getKey())->restore();

    expect(VariantItem::query()->where('variant_id', $variant->getKey())->pluck('id')->all())->toBe([$live->getKey()])
        ->and(fn () => VariantItem::withTrashed()->findOrFail($earlier->getKey())->restore())
        ->toThrow(ValidationException::class);
});
