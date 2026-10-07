<?php

declare(strict_types=1);

namespace Modules\Shop\Enums;

/**
 * What a product is, which decides whether its variants resolve to ERP items.
 */
enum ProductKind: string
{
    /**
     * Ships and is stocked: its variants are composed of ERP items.
     */
    case Physical = 'physical';

    /**
     * Delivered as a download or an entitlement: no ERP item behind it.
     */
    case Digital = 'digital';

    /**
     * Shown in the catalog but not sold through the shop.
     */
    case DisplayOnly = 'display_only';

    /**
     * Returns an 'in:...' validation rule string for all enum values.
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::values());
    }

    /**
     * Returns all enum values as an array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
