<?php

use App\Domain\Weather\Enums\WeatherMetric;
use App\Models\AppSetting;
use App\Services\Weather\UnitPreferences;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(LazilyRefreshDatabase::class);

it('converts displayed values while keeping persisted weather values in metric units', function () {
    AppSetting::query()->updateOrCreate(['key' => 'weather_units'], ['value' => 'imperial']);
    $preferences = app(UnitPreferences::class);

    expect($preferences->unit(WeatherMetric::Temperature))->toBe('°F')
        ->and($preferences->fromBase(WeatherMetric::Temperature, 25))->toBe(77.0)
        ->and($preferences->toBase(WeatherMetric::Temperature, 77))->toBe(25.0)
        ->and($preferences->fromBase(WeatherMetric::Precipitation, 25.4))->toBe(1.0)
        ->and($preferences->fromBase(WeatherMetric::WindSpeed, 10))->toBe(6.21371);
});
