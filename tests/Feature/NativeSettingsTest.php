<?php

use App\Models\AppSetting;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Services\Weather\UnitPreferences;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('lets people select their primary location and preferred units from settings', function () {
    $first = Location::factory()->default()->create(['name' => 'Managua']);
    $second = Location::factory()->create(['name' => 'León']);

    Native::fakeBridge();

    Native::visit('/settings')
        ->assertSee('Ubicación principal')
        ->assertSee('Fuente meteorológica: Open-Meteo')
        ->assertSee('Elver')
        ->set('defaultLocationChoice', 'León')
        ->set('unitsChoice', 'Imperial')
        ->assertSet('unitsChoice', 'Imperial')
        ->assertAccessible();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(app(UnitPreferences::class)->preference())->toBe('imperial');
});

it('stops native alert scheduling without deleting configured alerts when alerts are disabled', function () {
    $location = Location::factory()->default()->create();
    ClimateAlert::factory()->create(['location_id' => $location->id]);
    $bridge = Native::fakeBridge();

    Native::visit('/settings')
        ->set('notificationsEnabled', false)
        ->assertSet('notificationsEnabled', false);

    expect(AppSetting::query()->find('notifications_enabled')?->value)->toBe('0')
        ->and(ClimateAlert::query()->count())->toBe(1);
    $bridge->assertCalled('ClimateNotifications.SyncSchedule', fn (array $parameters): bool => $parameters['locations'] === []);
});
