<?php

declare(strict_types=1);

namespace Modules\Shop\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\FieldType;
use Modules\Core\Models\Field;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Services\PresetVersioningService;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Preset;

/**
 * Bootstraps the Shop module: the product content entity and its default preset.
 *
 * The product entity is the module's own (it is not a CMS entity type), so the module seeds it.
 * Per-category presets, the attribute sets of the storefront, are added by later work.
 */
final class ShopDatabaseSeeder extends Seeder
{
    private const string PRODUCT_ENTITY = 'product';

    private const string DEFAULT_PRESET = 'standard';

    /**
     * The body of a product, shared with the CMS (same name and definition as the CMS field).
     */
    private const string BODY_FIELD = 'content';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguarded(function (): void {
            $this->defaultEntities();
        });

        DynamicContentsService::getInstance()->clearAllCaches();
    }

    private function defaultEntities(): void
    {
        $this->logOperation(Entity::class);

        $entity_model = new Entity;

        $entity_model->getConnection()->transaction(function () use ($entity_model): void {
            if ($entity_model->newQuery()->withoutGlobalScopes()->where('name', self::PRODUCT_ENTITY)->exists()) {
                $this->command?->line('    - ' . self::PRODUCT_ENTITY . ' already exists');

                return;
            }

            /** @var Entity $entity */
            $entity = $this->create(Entity::class, [
                'name' => self::PRODUCT_ENTITY,
                'type' => EntityType::Products,
                'is_default' => true,
            ]);

            /** @var Preset $preset */
            $preset = $this->create(Preset::class, ['name' => self::DEFAULT_PRESET, 'entity_id' => $entity->id]);

            $preset->fields()->attach($this->bodyField()->getKey(), ['preset_id' => $preset->id, 'is_required' => true, 'default' => null]);

            // The snapshot is frozen after the fields are attached: BelongsToMany bulk operations
            // do not fire the pivot events that would otherwise version the preset.
            resolve(PresetVersioningService::class)->createVersion($preset);

            $this->command?->line('    - ' . self::PRODUCT_ENTITY . ' <fg=green>created</>');
        });
    }

    private function bodyField(): Model
    {
        return (new Field)->newQuery()->withoutGlobalScopes()->get()->keyBy('name')->get(self::BODY_FIELD)
            ?? $this->create(Field::class, ['name' => self::BODY_FIELD, 'type' => FieldType::Editor, 'options' => (object) [], 'is_translatable' => true]);
    }
}
