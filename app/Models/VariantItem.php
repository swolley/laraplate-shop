<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Modules\Core\Overrides\Model;
use Modules\ERP\Enums\ERPTables;
use Modules\ERP\Models\Item;
use Modules\Shop\Database\Factories\VariantItemFactory;
use Modules\Shop\Enums\ShopTables;
use Override;

/**
 * One ERP {@see Item} that composes a {@see ProductVariant} (E7b): a single row is a simple product,
 * several rows are a virtual bundle, and no row at all is an item-less variant (a digital or display-only
 * product, told apart by the product's `kind` and not by the row count).
 *
 * `role` is a closed two-value set (E31): `main` is the sole sellable item of a simple product or the
 * anchor of a range, `component` is a constituent of a bundle. It is a validated string, not an enum.
 *
 * It carries no `company_id` of its own: the tenant is derived through the {@see self::variant()} and its
 * product (E23). That the item and the product belong to the same company is a checkout concern, not
 * enforced here.
 *
 * @property int $variant_id
 * @property int $item_id
 * @property numeric-string $quantity
 * @property string $role
 */
final class VariantItem extends Model
{
    public const string ROLE_MAIN = 'main';

    public const string ROLE_COMPONENT = 'component';

    /**
     * @var string
     */
    #[Override]
    protected $table = ShopTables::VariantItems->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'variant_id',
        'item_id',
        'quantity',
        'role',
    ];

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $role_rule = ['string', Rule::in([self::ROLE_MAIN, self::ROLE_COMPONENT])];
        $rules['create'] = array_merge($rules['create'], [
            'variant_id' => ['required', 'integer', Rule::exists(ShopTables::ProductVariants->value, 'id')->whereNull('deleted_at')],
            'item_id' => ['required', 'integer', Rule::exists(ERPTables::Items->value, 'id')->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'role' => ['required', ...$role_rule],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'quantity' => ['sometimes', 'numeric', 'min:0.0001'],
            'role' => ['sometimes', ...$role_rule],
        ]);

        // A composition row never moves to another variant. The validated payload is the whole attribute
        // set (the key is always present), so the key is prohibited only when the write actually moves it.
        if ($this->isDirty('variant_id')) {
            $rules['update']['variant_id'] = ['prohibited'];
        }

        // Swapping the item is allowed, but the new one must exist.
        if ($this->isDirty('item_id')) {
            $rules['update']['item_id'] = ['required', 'integer', Rule::exists(ERPTables::Items->value, 'id')->whereNull('deleted_at')];
        }

        return $rules;
    }

    #[Override]
    protected static function newFactory(): VariantItemFactory
    {
        return VariantItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }
}
