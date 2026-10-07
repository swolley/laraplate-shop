<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Shop\Enums\ShopTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = ShopTables::ProductVariants->value;

        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();

            // E23: no company_id here — the variant derives its tenant from its product. The FK
            // cascades so a hard-deleted product never leaves orphan variants; the soft lifecycle is
            // handled by the Core soft-delete trait.
            $table->foreignId('product_id')
                ->constrained(ShopTables::Products->value, 'id', "{$table_name}_product_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'product_id');

            // At most one default per product, enforced by the model (a partial unique index is not
            // portable across the supported drivers).
            $table->boolean('is_default')->default(false);

            // E22: the purchase-selection axis (size, colour, ...) as a label/projection only — never a
            // source of truth and not relational.
            $table->json('attributes')->nullable();

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ShopTables::ProductVariants->value);
    }
};
