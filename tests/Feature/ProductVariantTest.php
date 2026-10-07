<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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

it('leaves the prior default intact when a promotion is rejected', function (): void {
    $product = Product::factory()->create();
    $current = ProductVariant::factory()->default()->for($product)->create();
    $candidate = ProductVariant::factory()->for($product)->create();

    // Core validates in `updating`, after `saving`: a demotion run too early would leave no default.
    expect(fn () => $candidate->update(['is_default' => true, 'attributes' => 'not-an-array']))
        ->toThrow(ValidationException::class);

    expect($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($product->refresh()->defaultVariant->is($current))->toBeTrue()
        ->and($candidate->fresh()->is_default)->toBeFalse();
});

it('demotes a trashed sibling so a restore cannot bring back a second default', function (): void {
    $product = Product::factory()->create();
    $trashed = ProductVariant::factory()->default()->for($product)->create();
    $trashed->delete();

    $current = ProductVariant::factory()->default()->for($product)->create();

    ProductVariant::withTrashed()->findOrFail($trashed->getKey())->restore();

    expect($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($product->refresh()->defaultVariant->is($current))->toBeTrue()
        ->and($trashed->fresh()->is_default)->toBeFalse();
});

it('keeps the current default when it is re-saved', function (): void {
    $product = Product::factory()->create();
    $current = ProductVariant::factory()->default()->for($product)->create();
    $sibling = ProductVariant::factory()->for($product)->create();

    $current->refresh()->update(['attributes' => ['size' => 'XL']]);
    $current->save();

    expect($current->fresh()->is_default)->toBeTrue()
        ->and($sibling->fresh()->is_default)->toBeFalse()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

it('leaves the existing default alone when a non-default variant is saved', function (): void {
    $product = Product::factory()->create();
    $current = ProductVariant::factory()->default()->for($product)->create();
    $sibling = ProductVariant::factory()->for($product)->create();

    $sibling->update(['attributes' => ['size' => 'S']]);
    ProductVariant::factory()->for($product)->create();

    expect($current->fresh()->is_default)->toBeTrue()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

it('starts as a non-default variant', function (): void {
    expect((new ProductVariant())->is_default)->toBeFalse();
});

it('never changes parent', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create())->create();
    $other_product = Product::factory()->create();

    expect(fn () => $variant->update(['product_id' => $other_product->getKey()]))
        ->toThrow(ValidationException::class);

    expect($variant->fresh()->product_id)->not->toBe($other_product->getKey());
});

it('cannot be created under a trashed product', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    expect(fn () => ProductVariant::factory()->for($product)->create())
        ->toThrow(ValidationException::class);
});
