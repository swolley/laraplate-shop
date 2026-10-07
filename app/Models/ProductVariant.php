<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\Shop\Database\Factories\ProductVariantFactory;
use Modules\Shop\Enums\ShopTables;
use Override;

/**
 * The sellable unit beneath a {@see Product}. A product has one or more variants and exactly one of
 * them is the default.
 *
 * It carries no `company_id` of its own: the tenant is derived from the {@see self::product()} (E23).
 * `attributes` holds the purchase-selection axis (size, colour, ...) as a label/projection only — never
 * a source of truth and not relational (E22).
 *
 * @property int $product_id
 * @property bool $is_default
 * @property array<string, mixed>|null $attributes
 */
final class ProductVariant extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = ShopTables::ProductVariants->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'product_id',
        'is_default',
        'attributes',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules['create'] = array_merge($rules['create'], [
            'product_id' => ['required', 'integer', 'exists:' . ShopTables::Products->value . ',id'],
            'is_default' => ['sometimes', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'is_default' => ['sometimes', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);

        return $rules;
    }

    /**
     * Enforce a single default variant per product: when a variant is saved with `is_default = true`,
     * every other variant of the same product — trashed ones included, so a later restore cannot bring
     * back a second default — is reset to false. An existing variant whose flag did not change is left
     * alone, so a plain re-save never demotes the current default.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (self $variant): void {
            if ($variant->is_default !== true || ($variant->exists && ! $variant->isDirty('is_default'))) {
                return;
            }

            $variant->newQueryWithoutScopes()
                ->where('product_id', $variant->product_id)
                ->when($variant->exists, static fn ($query) => $query->whereKeyNot($variant->getKey()))
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'attributes' => 'array',
        ];
    }
}
