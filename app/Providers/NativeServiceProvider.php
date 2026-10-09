<?php

namespace App\Providers;

use Donmanueldev\NativephpCharts\NativePHPChartsServiceProvider;
use Elver\ClimateNotifications\ClimateNotificationsServiceProvider;
use Elver\Geolocation\ElverGeolocationServiceProvider;
use Illuminate\Support\ServiceProvider;
use Momotombo\NativephpAppearance\AppearanceServiceProvider;
use Momotombo\NativephpSettings\SettingsServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use S2BR\MobileSplashscreen\MobileSplashscreenServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            NativeUIServiceProvider::class,
            BrowserServiceProvider::class,
            NativePHPChartsServiceProvider::class,
            ElverGeolocationServiceProvider::class,
            ClimateNotificationsServiceProvider::class,
            MobileSplashscreenServiceProvider::class,
            AppearanceServiceProvider::class,
            SettingsServiceProvider::class,

        ];
    }
}
