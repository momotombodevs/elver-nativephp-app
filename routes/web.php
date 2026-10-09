<?php

use App\NativeComponents\AddLocation;
use App\NativeComponents\ChartExplorer;
use App\NativeComponents\ClimateAlerts;
use App\NativeComponents\CreateClimateAlert;
use App\NativeComponents\Home;
use App\NativeComponents\Onboarding;
use App\NativeComponents\SavedLocations;
use App\NativeComponents\Settings;
use App\NativeLayouts\MainTabsLayout;
use Illuminate\Support\Facades\Route;

Route::native('/onboarding', Onboarding::class);

Route::nativeGroup(MainTabsLayout::class, function (): void {
    Route::native('/', Home::class);
    Route::native('/explorer', ChartExplorer::class);
    Route::native('/locations', SavedLocations::class);
    Route::native('/locations/add', AddLocation::class);
    Route::native('/alerts', ClimateAlerts::class);
    Route::native('/alerts/create', CreateClimateAlert::class);
    Route::native('/alerts/location/{locationId}/alert/{alertId}', ClimateAlerts::class);
    Route::native('/settings', Settings::class);
});
