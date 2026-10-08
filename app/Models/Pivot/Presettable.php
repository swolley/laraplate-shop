<?php

declare(strict_types=1);

namespace Modules\Shop\Models\Pivot;

use Modules\Core\Models\Pivot\Presettable as CorePresettable;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Preset;
use Override;

/**
 * @property int $entity_id
 * @property int $id
 */
final class Presettable extends CorePresettable
{
    #[Override]
    protected function presetModelClass(): string
    {
        return Preset::class;
    }

    #[Override]
    protected function entityModelClass(): string
    {
        return Entity::class;
    }
}
