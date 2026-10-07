<?php

declare(strict_types=1);

namespace Modules\Shop\Enums;

use Modules\Core\Enums\Concerns\HasModuleTablesUtils;

enum ShopTables: string
{
    use HasModuleTablesUtils;

    // shop models
    case Products = 'shop_products';
    case ProductVariants = 'shop_product_variants';

    // composition of a variant from ERP items
    case VariantItems = 'shop_variant_items';
}
