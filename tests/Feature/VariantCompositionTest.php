<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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

    // Item-less is a choice made by the product kind, not a constraint of the variant: a row can still be added.
    $row = VariantItem::factory()->main()->for($variant, 'variant')->create();

    expect($variant->refresh()->items)->toHaveCount(1)
        ->and($variant->items->first()->is($row))->toBeTrue();
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

it('keeps resolving the item of a row after the item is soft-deleted, while erpItems leaves it out', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $row = VariantItem::factory()->main()->for($variant, 'variant')->create();

    expect($variant->erpItems)->toHaveCount(1);

    $row->item->delete();

    $variant->refresh();
    $row = $variant->items->first();

    expect($variant->items)->toHaveCount(1)
        ->and($row->item)->not->toBeNull()
        ->and($row->item->trashed())->toBeTrue()
        ->and($row->item->is(Item::withTrashed()->findOrFail($row->item_id)))->toBeTrue()
        ->and($variant->erpItems)->toBeEmpty();
});

it('rejects a missing or soft-deleted item', function (): void {
    expect(fn () => VariantItem::factory()->create(['item_id' => 999_999_999]))
        ->toThrow(ValidationException::class);

    $item = Item::factory()->create();
    $item->delete();

    expect(fn () => VariantItem::factory()->create(['item_id' => $item->getKey()]))
        ->toThrow(ValidationException::class);
});

it('never moves a row to another variant', function (): void {
    $row = VariantItem::factory()->main()->create();
    $original_variant_id = $row->variant_id;

    expect(fn () => $row->update(['variant_id' => ProductVariant::factory()->create()->getKey()]))
        ->toThrow(ValidationException::class);

    expect($row->fresh()->variant_id)->toBe($original_variant_id);
});

it('rejects swapping the row to a nonexistent item', function (): void {
    $row = VariantItem::factory()->main()->create();
    $original_item_id = $row->item_id;

    expect(fn () => $row->update(['item_id' => 999_999_999]))
        ->toThrow(ValidationException::class);

    expect($row->fresh()->item_id)->toBe($original_item_id);

    $other_item = Item::factory()->create();
    $row->refresh()->update(['item_id' => $other_item->getKey()]);

    expect($row->fresh()->item_id)->toBe($other_item->getKey());
});

it('rejects updating the quantity to zero', function (): void {
    $row = VariantItem::factory()->main()->create();

    expect(fn () => $row->update(['quantity' => 0]))
        ->toThrow(ValidationException::class);

    expect($row->fresh()->quantity)->toBe('1.0000');
});

it('bounds the quantity to the column scale and range', function (): void {
    // Five decimals do not fit decimal(15,4), and eleven integer digits plus four decimals is the ceiling.
    expect(fn () => VariantItem::factory()->create(['quantity' => '1.00001']))
        ->toThrow(ValidationException::class)
        ->and(fn () => VariantItem::factory()->create(['quantity' => '100000000000']))
        ->toThrow(ValidationException::class)
        ->and(fn () => VariantItem::factory()->create(['quantity' => 1e20]))
        ->toThrow(ValidationException::class);

    // Only persistence is asserted at the ceiling: the sqlite test driver stores the decimal as a float, so
    // the read-back string is not exact there.
    $row = VariantItem::factory()->create(['quantity' => '99999999999.9999']);

    expect($row->exists)->toBeTrue();

    expect(fn () => $row->update(['quantity' => '1.00001']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $row->update(['quantity' => '100000000000']))
        ->toThrow(ValidationException::class);
});

it('rejects the same item twice on one variant among live rows', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $item = Item::factory()->create();
    $first = VariantItem::factory()->component()->for($variant, 'variant')->for($item, 'item')->create();

    expect(fn () => VariantItem::factory()->component()->for($variant, 'variant')->for($item, 'item')->create())
        ->toThrow(ValidationException::class);

    // Another variant may use the same item.
    VariantItem::factory()->component()->for(ProductVariant::factory()->create(), 'variant')->for($item, 'item')->create();

    // Swapping a second row onto an item the variant already has is a duplicate as well.
    $second = VariantItem::factory()->component()->for($variant, 'variant')->create();

    expect(fn () => $second->update(['item_id' => $item->getKey()]))
        ->toThrow(ValidationException::class);

    // A soft-deleted row no longer counts, and a plain re-save of a live row does not clash with itself.
    $first->delete();
    VariantItem::factory()->component()->for($variant, 'variant')->for($item, 'item')->create();
    // The rejected swap above left the item dirty in memory, so reload the row before saving it again.
    $second->refresh()->update(['quantity' => 2]);

    expect($variant->refresh()->items)->toHaveCount(2);
});

it('blocks a hard delete of an item that still composes a variant', function (): void {
    $row = VariantItem::factory()->main()->create();

    expect(fn () => $row->item->forceDelete())->toThrow(QueryException::class);

    expect(Item::withTrashed()->find($row->item_id))->not->toBeNull()
        ->and($row->fresh())->not->toBeNull();
});

it('removes the composition rows with a hard-deleted variant', function (): void {
    $variant = ProductVariant::factory()->for(Product::factory()->create(['kind' => ProductKind::Physical]))->create();
    $kept_item = Item::factory()->create();
    VariantItem::factory()->component()->for($variant, 'variant')->for($kept_item, 'item')->create();
    $trashed = VariantItem::factory()->component()->for($variant, 'variant')->create();
    $trashed->delete();
    $other_row = VariantItem::factory()->main()->create();

    expect(VariantItem::withTrashed()->where('variant_id', $variant->getKey())->count())->toBe(2);

    $variant->forceDelete();

    expect(VariantItem::withTrashed()->where('variant_id', $variant->getKey())->count())->toBe(0)
        ->and(VariantItem::withTrashed()->whereKey($other_row->getKey())->exists())->toBeTrue()
        ->and(Item::withTrashed()->whereKey($kept_item->getKey())->exists())->toBeTrue();
});
