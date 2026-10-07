<?php

declare(strict_types=1);

namespace Modules\Shop\Casts;

use Modules\Core\Contracts\IDynamicEntityTypable;

/**
 * The dynamic-content entity types the Shop module owns. The product entity is the module's to
 * seed: it is deliberately not a CMS {@see \Modules\CMS\Casts\EntityType} case.
 */
enum EntityType: string implements IDynamicEntityTypable
{
    /**
     * The entity a product's content (a CMS `Content`) is created against.
     */
    case Products = 'products';

    /**
     * Get all values as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if value is valid.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Get validation rules for Laravel.
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::values());
    }

    public function toScalar(): string
    {
        return $this->value;
    }
}
