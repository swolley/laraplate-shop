<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Modules\Core\Models\Entity as CoreEntity;
use Modules\Shop\Casts\EntityType;
use Override;

final class Entity extends CoreEntity
{
    #[Override]
    protected static function getEntityTypeEnumClass(): string
    {
        return EntityType::class;
    }
}
