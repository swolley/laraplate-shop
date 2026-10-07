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
 * A variant needs exactly one `main` row only when it is a simple product; that is enforced at checkout,
 * not here, so a bundle of `component` rows and a variant with no row at all are both valid in storage.
 * The same item can appear at most once among a variant's live rows, so a quantity is never split across
 * two rows and double counted.
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
     * The ERP item this row refers to. A composition row always resolves its item, even a soft-deleted
     * (discontinued) one, so reading the full composition never hits a null; check `$row->item->trashed()`
     * for its lifecycle. {@see ProductVariant::erpItems()} is the live-items-only view instead.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $role_rule = ['string', Rule::in([self::ROLE_MAIN, self::ROLE_COMPONENT])];
        // Bounded to the `decimal(15,4)` column: at most four decimals and 11 integer digits.
        $quantity_rule = ['numeric', 'decimal:0,4', 'min:0.0001', 'max:99999999999.9999'];

        $rules['create'] = array_merge($rules['create'], [
            'variant_id' => ['required', 'integer', Rule::exists(ShopTables::ProductVariants->value, 'id')->whereNull('deleted_at')],
            'item_id' => $this->itemRules(),
            'quantity' => ['required', ...$quantity_rule],
            'role' => ['required', ...$role_rule],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'quantity' => ['sometimes', ...$quantity_rule],
            'role' => ['sometimes', ...$role_rule],
        ]);

        // A composition row never moves to another variant. The validated payload is the whole attribute
        // set (the key is always present), so the key is prohibited only when the write actually moves it.
        if ($this->isDirty('variant_id')) {
            $rules['update']['variant_id'] = ['prohibited'];
        }

        // Swapping the item is allowed, but the new one must exist and not already compose this variant.
        if ($this->isDirty('item_id')) {
            $rules['update']['item_id'] = $this->itemRules();
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

    /**
     * An existing, live ERP item that does not already compose this variant among its live rows. On an
     * update it is only applied when the item actually changes, so the row never clashes with itself.
     *
     * @return list<mixed>
     */
    private function itemRules(): array
    {
        return [
            'required',
            'integer',
            Rule::exists(ERPTables::Items->value, 'id')->whereNull('deleted_at'),
            Rule::unique(ShopTables::VariantItems->value)
                ->where('variant_id', $this->variant_id)
                ->whereNull('deleted_at'),
        ];
    }
}
