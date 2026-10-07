<?php

declare(strict_types=1);

use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Entity as CmsEntity;
use Modules\CMS\Models\Preset as CmsPreset;
use Modules\Core\Models\Field;
use Modules\Core\Services\DynamicContentsService;
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
    $snapshot = $presettable->fields_snapshot;

    expect($entity->type)->toBe(EntityType::Products)
        ->and($snapshot)->toHaveCount(1)
        ->and($snapshot[0]['name'])->toBe('content')
        ->and($snapshot[0]['pivot']['is_required'])->toBeTrue();
});

it('seeds the product entity only once', function (): void {
    $versions = static fn (): array => Presettable::query()
        ->withTrashed()
        ->orderBy('id')
        ->get()
        ->map(static fn (Presettable $presettable): array => [$presettable->id, $presettable->version, $presettable->deleted_at !== null])
        ->all();

    setupShopEntities();
    $first_run = $versions();

    setupShopEntities();

    $entity = Entity::query()->where('type', EntityType::Products)->sole();

    expect(Preset::query()->where('entity_id', $entity->id)->count())->toBe(1)
        ->and($versions())->toBe($first_run)
        ->and(array_filter($first_run, static fn (array $row): bool => ! $row[2]))->toHaveCount(1);
});

it('does not seed a second product entity once the first was renamed or deactivated', function (): void {
    setupShopEntities();

    $entity = Entity::query()->where('type', EntityType::Products)->sole();
    $entity->forceFill(['name' => 'renamed-product', 'is_active' => false])->save();

    setupShopEntities();

    expect(Entity::query()->withoutGlobalScopes()->where('type', EntityType::Products)->count())->toBe(1)
        ->and(Preset::query()->withoutGlobalScopes()->count())->toBe(1);
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

it('shares a single content field with the CMS when the CMS is seeded first', function (): void {
    $this->artisan('permission:refresh');
    $this->seed(CMSDatabaseSeeder::class);

    setupShopEntities();

    $field = Field::query()->withTrashed()->where('name', 'content')->sole();
    $presettable = shopProductPresettable();

    expect($presettable->fields_snapshot)->toHaveCount(1)
        ->and($presettable->fields_snapshot[0]['field_id'])->toBe($field->id);
});

it('shares a single content field with the CMS when the shop is seeded first', function (): void {
    setupShopEntities();

    $this->artisan('permission:refresh');
    $this->seed(CMSDatabaseSeeder::class);

    $field = Field::query()->withTrashed()->where('name', 'content')->sole();
    $presettable = shopProductPresettable();

    expect($presettable->fields_snapshot)->toHaveCount(1)
        ->and($presettable->fields_snapshot[0]['field_id'])->toBe($field->id);
});

it('restores a soft-deleted content field instead of attaching it trashed', function (): void {
    $this->artisan('permission:refresh');
    $this->seed(CMSDatabaseSeeder::class);

    $trashed = Field::query()->where('name', 'content')->sole();
    $trashed->delete();

    setupShopEntities();

    $live = Field::query()->where('name', 'content')->sole();
    $presettable = shopProductPresettable();

    expect($live->id)->toBe($trashed->id)
        ->and(Field::query()->withTrashed()->where('name', 'content')->count())->toBe(1)
        ->and($presettable->fields_snapshot)->toHaveCount(1)
        ->and($presettable->fields_snapshot[0]['field_id'])->toBe($live->id);
});

it('resolves the dynamic content metadata of the product type to the shop classes', function (): void {
    setupShopEntities();

    $service = DynamicContentsService::getInstance();

    expect($service->fetchAvailableEntities(EntityType::Products)->sole())->toBeInstanceOf(Entity::class)
        ->and($service->fetchAvailablePresets(EntityType::Products)->sole())->toBeInstanceOf(Preset::class)
        ->and($service->fetchAvailablePresettables(EntityType::Products)->sole())->toBeInstanceOf(Presettable::class);
});

it('keeps the shop entity layer apart from the cms one', function (): void {
    setupShopEntities();

    $shop_entities = Entity::query()->withoutGlobalScopes()->count();
    $shop_presets = Preset::query()->withoutGlobalScopes()->count();

    $this->artisan('permission:refresh');
    $this->seed(CMSDatabaseSeeder::class);

    $shop_entity = Entity::query()->where('type', EntityType::Products)->sole();

    expect(Entity::query()->withoutGlobalScopes()->count())->toBe($shop_entities)
        ->and(Preset::query()->withoutGlobalScopes()->count())->toBe($shop_presets)
        ->and(CmsEntity::query()->withoutGlobalScopes()->count())->toBeGreaterThan(0)
        ->and(CmsEntity::query()->withoutGlobalScopes()->whereKey($shop_entity->id)->exists())->toBeFalse()
        ->and(CmsPreset::query()->withoutGlobalScopes()->where('entity_id', $shop_entity->id)->exists())->toBeFalse();
});
