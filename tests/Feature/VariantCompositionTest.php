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

it('a simple variant has one main item', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $item = Item::factory()->create();

    VariantItem::factory()->main()->for($variant, 'variant')->for($item, 'item')->create(['quantity' => 1]);

    $variant->refresh();

    expect($variant->items)->toHaveCount(1)
        ->and($variant->items->first()->role)->toBe(VariantItem::ROLE_MAIN)
        ->and($variant->items->first()->variant->is($variant))->toBeTrue()
        ->and($variant->items->first()->item->is($item))->toBeTrue()
        ->and($variant->erpItems)->toHaveCount(1)
        ->and($variant->erpItems->first()->is($item))->toBeTrue()
        ->and($variant->erpItems->first()->pivot->role)->toBe(VariantItem::ROLE_MAIN);
});

it('a bundle variant has component items', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $items = Item::factory()->count(3)->create();

    foreach ($items as $index => $item) {
        VariantItem::factory()->component()->for($variant, 'variant')->for($item, 'item')->create(['quantity' => $index + 1.5]);
    }

    $variant->refresh();

    expect($variant->items)->toHaveCount(3)
        ->and($variant->items->every(static fn (VariantItem $row): bool => $row->role === VariantItem::ROLE_COMPONENT))->toBeTrue()
        ->and($variant->erpItems->pluck('id')->sort()->values()->all())->toBe($items->pluck('id')->sort()->values()->all())
        // The pivot exposes the raw column value; the model cast is what normalises it to four decimals.
        ->and((float) $variant->erpItems->firstWhere('id', $items[2]->id)->pivot->quantity)->toBe(3.5)
        ->and($variant->items->firstWhere('item_id', $items[0]->id)->quantity)->toBe('1.5000');
});

it('a digital product has no variant items', function (): void {
    $product = Product::factory()->create(['kind' => ProductKind::Digital]);
    $variant = ProductVariant::factory()->default()->for($product)->create();

    expect($product->kind)->toBe(ProductKind::Digital)
        ->and($variant->exists)->toBeTrue()
        ->and($variant->items)->toBeEmpty()
        ->and($variant->erpItems)->toBeEmpty();
});

it('rejects a role outside main and component', function (): void {
    expect(fn () => VariantItem::factory()->create(['role' => 'accessory']))
        ->toThrow(ValidationException::class);

    $row = VariantItem::factory()->main()->create();

    expect(fn () => $row->update(['role' => 'accessory']))
        ->toThrow(ValidationException::class);

    expect($row->fresh()->role)->toBe(VariantItem::ROLE_MAIN);
});

it('rejects a quantity that is not positive', function (): void {
    expect(fn () => VariantItem::factory()->create(['quantity' => 0]))
        ->toThrow(ValidationException::class);
});

it('leaves a soft-deleted composition row out of the variant items', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $kept = VariantItem::factory()->component()->for($variant, 'variant')->create();
    $removed = VariantItem::factory()->component()->for($variant, 'variant')->create();

    $removed->delete();

    $variant->refresh();

    expect($variant->items)->toHaveCount(1)
        ->and($variant->items->first()->is($kept))->toBeTrue()
        ->and($variant->erpItems->pluck('id')->all())->toBe([$kept->item_id]);
});
