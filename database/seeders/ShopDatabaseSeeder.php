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

    /**
     * Creates the product entity unless one already exists, whatever its name or activation: the
     * guard is the entity type, so an operator renaming or deactivating it does not get a second
     * default entity and preset on the next seed.
     *
     * The preset snapshot is frozen after the fields are attached: BelongsToMany bulk operations
     * do not fire the pivot events that would otherwise version the preset.
     */
    private function defaultEntities(): void
    {
        $this->logOperation(Entity::class);

        $entity_model = new Entity;

        $entity_model->getConnection()->transaction(function () use ($entity_model): void {
            if ($entity_model->newQuery()->withoutGlobalScopes()->where('type', EntityType::Products)->exists()) {
                $this->report(self::PRODUCT_ENTITY . ' already exists');

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

            $preset->fields()->attach($this->bodyField()->getKey(), ['is_required' => true, 'default' => null]);

            resolve(PresetVersioningService::class)->createVersion($preset);

            $this->report(self::PRODUCT_ENTITY . ' <fg=green>created</>');
        });
    }

    /**
     * The body field is shared with the CMS: reuse its `content` field by name and create the same
     * definition only when none exists. A soft-deleted one is restored rather than attached
     * trashed (the preset snapshot would come out empty) or recreated (Core's `unique` rule on the
     * field name does not look at soft-deleted rows, so the create would be refused).
     */
    private function bodyField(): Field
    {
        $field_model = new Field;
        $existing = $field_model->newQuery()->withTrashed()->where($field_model->qualifyColumn('name'), self::BODY_FIELD)->first();

        if ($existing instanceof Field) {
            if ($existing->trashed()) {
                $existing->restore();
                $this->report(self::BODY_FIELD . ' <fg=green>restored</>');
            }

            return $existing;
        }

        /** @var Field $created */
        $created = $this->create(Field::class, ['name' => self::BODY_FIELD, 'type' => FieldType::Editor, 'options' => (object) [], 'is_translatable' => true]);

        $this->report(self::BODY_FIELD . ' <fg=green>created</>');

        return $created;
    }

    /**
     * Writes a progress line when the seeder runs from the console; silent otherwise (tests).
     */
    private function report(string $message): void
    {
        $this->command?->line('    - ' . $message);
    }
}
