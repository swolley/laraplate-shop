<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Models\Content;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Preset as CorePreset;
use Modules\Shop\Casts\EntityType;
use Override;

/**
 * Shop preset model; behaviour lives in Core. A product's body is a CMS `Content`, so the
 * dynamic content a preset describes is the CMS one.
 */
final class Preset extends CorePreset
{
    #[Override]
    protected static function getRelatedModelClass(): string
    {
        return Content::class;
    }

    #[Override]
    protected function newBaseQueryBuilder(): Builder
    {
        return parent::newBaseQueryBuilder()->whereExists(function (Builder $query): void {
            $entities_table = CoreTables::Entities->value;
            $query->select(DB::raw('1'))
                ->from($entities_table)
                ->whereColumn("{$entities_table}.id", CoreTables::Presets->value . '.entity_id')
                ->whereIn("{$entities_table}.type", EntityType::values());
        });
    }
}
