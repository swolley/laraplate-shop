<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Modules\Core\Overrides\Model;
use Modules\ERP\Models\Item;
use Modules\Shop\Database\Factories\ProductVariantFactory;
use Modules\Shop\Enums\ShopTables;
use Override;

/**
 * The sellable unit beneath a {@see Product}. A product has one or more variants and exactly one of
 * them is the default.
 *
 * It carries no `company_id` of its own: the tenant is derived from the {@see self::product()} (E23).
 * `attributes` holds the purchase-selection axis (size, colour, ...) as a label/projection only, never
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
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'is_default' => false,
    ];

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
     * The composition rows of this variant (E7b): one is a simple product, several are a bundle, none is
     * an item-less variant.
     *
     * @return HasMany<VariantItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VariantItem::class, 'variant_id');
    }

    /**
     * The ERP items this variant is composed of, through the composition rows. A soft-deleted row is left
     * out, exactly as it is from {@see self::items()}; use that relation to write rows.
     *
     * @return BelongsToMany<Item, $this>
     */
    public function erpItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, ShopTables::VariantItems->value, 'variant_id', 'item_id')
            ->withPivot('quantity', 'role')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules['create'] = array_merge($rules['create'], [
            'product_id' => ['required', 'integer', Rule::exists(ShopTables::Products->value, 'id')->whereNull('deleted_at')],
            'is_default' => ['sometimes', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'is_default' => ['sometimes', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);

        // A variant never changes parent. The validated payload is the whole attribute set (the key is
        // always present), so the key is prohibited only when the write actually moves it.
        if ($this->isDirty('product_id')) {
            $rules['update']['product_id'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Enforce a single default variant per product: once a variant has been saved as the default, every
     * other variant of the same product (trashed ones included, so a later restore cannot bring back a
     * second default) is reset to false.
     *
     * It runs on `saved`, not `saving`: Core validates and authorizes in `creating`/`updating`, which
     * fire after `saving`, so demoting earlier would wipe the current default when the write is then
     * rejected. A save that did not touch the flag (a plain re-save, a non-default write) leaves the
     * siblings alone. The bulk update fires no model events, so it cannot recurse.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saved(static function (self $variant): void {
            // `isDirty` still reflects this save inside `saved` (the original is synced afterwards).
            // `wasRecentlyCreated` would not do: it stays true for the life of the instance, so a stale
            // in-memory default re-saved later would demote whatever is the real default by then.
            if ($variant->is_default !== true || ! $variant->isDirty(['is_default', 'product_id'])) {
                return;
            }

            $variant->newQueryWithoutScopes()
                ->where('product_id', $variant->product_id)
                ->whereKeyNot($variant->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    #[Override]
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
