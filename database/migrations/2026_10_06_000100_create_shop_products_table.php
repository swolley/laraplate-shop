<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Enums\CMSTables;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Helpers\ERPMigrateUtils;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Enums\ShopTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = ShopTables::Products->value;

        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();

            // E23: the product carries its tenant via ERP BelongsToCompany.
            ERPMigrateUtils::companyForeign($table, indexed: false);

            // E16: one extender per content — content_id is mandatory and unique. The FK cascades on
            // delete so a hard-deleted content can never leave a bodiless product (E17); the soft
            // lifecycle is mirrored in PHP by the content-extension seam.
            $table->foreignId('content_id')
                ->unique()
                ->constrained(CMSTables::Contents->value, 'id', "{$table_name}_content_id_FK")
                ->cascadeOnDelete();

            $table->string('kind')->default(ProductKind::Physical->value);
            $table->boolean('is_published_in_shop')->default(false);
            $table->boolean('featured')->default(false);
            $table->date('release_date')->nullable();
            $table->json('metadata')->nullable();

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->index(['company_id', 'is_published_in_shop'], "{$table_name}_company_published_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ShopTables::Products->value);
    }
};
