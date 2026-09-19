<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shop\Http\Controllers\ShopController;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::resource('shops', ShopController::class)->names('shop');
});
