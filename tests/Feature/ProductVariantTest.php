<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Shop\Enums\ShopTables;
use Modules\Shop\Models\Product;
use Modules\Shop\Models\ProductVariant;

beforeEach(function (): void {
    setupShopEntities();
});

it('a product has one or more variants', function (): void {
    $product = Product::factory()->create();

    ProductVariant::factory()->default()->for($product)->create();
    ProductVariant::factory()->for($product)->count(2)->create();

    expect($product->variants()->count())->toBe(3)
        ->and($product->variants->every(static fn (ProductVariant $variant): bool => $variant->product->is($product)))->toBeTrue();
});

it('keeps exactly one default variant per product', function (): void {
    $product = Product::factory()->create();
    $other_product = Product::factory()->create();

    $first = ProductVariant::factory()->default()->for($product)->create();
    $other_default = ProductVariant::factory()->default()->for($other_product)->create();
    $second = ProductVariant::factory()->default()->for($product)->create();

    expect($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($product->defaultVariant->is($second))->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse()
        // The invariant is per product: another product's default is left alone.
        ->and($other_default->fresh()->is_default)->toBeTrue()
        ->and($other_product->defaultVariant->is($other_default))->toBeTrue();

    // Promoting an existing sibling moves the default as well.
    $first->refresh()->update(['is_default' => true]);

    expect($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($product->refresh()->defaultVariant->is($first))->toBeTrue();
});

it('casts the attributes column to an array', function (): void {
    $attributes = ['size' => 'M', 'colour' => 'red'];

    $variant = ProductVariant::factory()->for(Product::factory()->create())->create(['attributes' => $attributes]);

    expect($variant->fresh()->attributes)->toBe($attributes);
});

it('derives its company from the product and has no company_id column', function (): void {
    expect(Schema::hasColumn(ShopTables::ProductVariants->value, 'company_id'))->toBeFalse();

    $variant = ProductVariant::factory()->for(Product::factory()->create())->create();

    expect($variant->getAttributes())->not->toHaveKey('company_id')
        ->and($variant->product->company_id)->not->toBeNull();
});
