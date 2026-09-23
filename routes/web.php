<?php

use App\NativeComponents\ChartExplorer;
use App\NativeComponents\ClimateAlerts;
use App\NativeComponents\Home;
use App\NativeComponents\SavedLocations;
use App\NativeLayouts\MainTabsLayout;
use Illuminate\Support\Facades\Route;

Route::nativeGroup(MainTabsLayout::class, function (): void {
    Route::native('/', Home::class);
    Route::native('/explorer', ChartExplorer::class);
    Route::native('/locations', SavedLocations::class);
    Route::native('/alerts', ClimateAlerts::class);
});
