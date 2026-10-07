<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Services\DynamicContentsService;
use Modules\Shop\Database\Seeders\ShopDatabaseSeeder;
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

    DynamicContentsService::getInstance()->clearAllCaches();
}
