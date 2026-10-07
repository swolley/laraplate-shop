<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\Shop\Enums\ShopTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = ShopTables::VariantItems->value;

        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();

            // E23: no company_id here: the row derives its tenant through the variant, then the product.
            // The FK cascades so a hard-deleted variant never leaves orphan composition rows.
            $table->foreignId('variant_id')
                ->constrained(ShopTables::ProductVariants->value, 'id', "{$table_name}_variant_id_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'variant_id');

            // The ERP item is restricted, not cascaded, and that guards only a HARD delete: a bundle
            // component must not silently vanish because its item was force-deleted. A soft-deleted item
            // stays referenced by its composition row on purpose, so the row keeps resolving the item.
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->restrictOnDelete();
            MigrateUtils::prefixIndex($table, 'item_id');

            $table->decimal('quantity', 15, 4);

            // E31: closed set `main` / `component`, enforced by the model rules (a CHECK constraint is
            // not portable across the supported drivers).
            $table->string('role', 16);

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ShopTables::VariantItems->value);
    }
};
