<?php

declare(strict_types=1);

use Modules\CMS\Models\Content;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Enums\ShopTables;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Pivot\Presettable;
use Modules\Shop\Models\Preset;

it('names the catalog tables with the shop prefix', function (): void {
    expect(ShopTables::Products->value)->toBe('shop_products')
        ->and(ShopTables::ProductVariants->value)->toBe('shop_product_variants')
        ->and(ShopTables::VariantItems->value)->toBe('shop_variant_items');
});

it('declares the three product kinds', function (): void {
    expect(array_column(ProductKind::cases(), 'value'))->toBe(['physical', 'digital', 'display_only'])
        ->and(ProductKind::Physical->value)->toBe('physical')
        ->and(ProductKind::Digital->value)->toBe('digital')
        ->and(ProductKind::DisplayOnly->value)->toBe('display_only')
        ->and(ProductKind::validationRule())->toBe('in:physical,digital,display_only');
});

it('seeds the product entity with a default preset', function (): void {
    setupShopEntities();

    $entity = Entity::query()->where('type', EntityType::Products)->sole();
    $preset = Preset::query()->where('entity_id', $entity->id)->sole();
    $presettable = Presettable::query()->where('entity_id', $entity->id)->where('preset_id', $preset->id)->sole();

    expect($entity->type)->toBe(EntityType::Products)
        ->and($presettable->fields_snapshot)->not->toBeEmpty();
});

it('seeds the product entity only once', function (): void {
    setupShopEntities();
    setupShopEntities();

    $entity = Entity::query()->where('type', EntityType::Products)->sole();

    expect(Preset::query()->where('entity_id', $entity->id)->count())->toBe(1)
        ->and(Presettable::query()->where('entity_id', $entity->id)->count())->toBe(1);
});

it('creates a content against the product entity and its default preset', function (): void {
    setupShopEntities();

    $entity = Entity::query()->where('type', EntityType::Products)->sole();
    $presettable = Presettable::query()->where('entity_id', $entity->id)->sole();

    $content = Content::query()->create([
        'entity_id' => $entity->id,
        'presettable_id' => $presettable->id,
        'valid_from' => now(),
    ]);

    expect($content->exists)->toBeTrue()
        ->and($content->entity_id)->toBe($entity->id)
        ->and($content->presettable_id)->toBe($presettable->id);
});
