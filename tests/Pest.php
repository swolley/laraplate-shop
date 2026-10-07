<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Services\DynamicContentsService;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Database\Seeders\ShopDatabaseSeeder;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Pivot\Presettable;
use Modules\Shop\Tests\TestCase;

pest()->extend(TestCase::class)
    ->in(__DIR__ . '/Unit', __DIR__ . '/Feature');

pest()->use(RefreshDatabase::class)
    ->in(__DIR__ . '/Feature');

/*
|--------------------------------------------------------------------------
| Hooks
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    DynamicContentsService::reset();
})->in(__DIR__ . '/Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Seeds the product Entity, its default Preset and the frozen Presettable version, so that a
 * product `Content` can be created against them. Runs the module seeder itself, which is
 * idempotent, so the tests and a real install cannot drift apart.
 */
function setupShopEntities(): void
{
    DynamicContentsService::reset();

    resolve(ShopDatabaseSeeder::class)->run();
}

/**
 * The active version of the product entity's default preset: the presettable a product `Content`
 * is created against. Scoped to the product entity because presettables are not partitioned by
 * module.
 */
function shopProductPresettable(): Presettable
{
    return Presettable::query()
        ->where('entity_id', Entity::query()->where('type', EntityType::Products)->sole()->id)
        ->sole();
}
