<?php

declare(strict_types=1);

namespace Modules\Shop\Providers;

use Modules\Core\Overrides\ModuleServiceProvider;
use Override;

final class ShopServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'Shop';

    #[Override]
    protected string $nameLower = 'shop';

    /**
     * Provider classes to register.
     *
     * @var array<int, class-string>
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
