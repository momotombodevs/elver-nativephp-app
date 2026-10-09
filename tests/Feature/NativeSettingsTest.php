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
        ->assertElement('native_root_tabs', fn (array $node): bool => ($node['props']['nav_back'] ?? false) === true
            && ($node['props']['hide_tab_bar'] ?? false) === true)
        ->assertSee('Ubicación principal')
        ->assertElement('list_item', fn (array $node): bool => ($node['ref'] ?? null) === 'settings-manage-alerts')
        ->assertSee('Administrar alertas')
        ->assertDontSee('Fuente meteorológica: Open-Meteo')
        ->assertDontSee('Elver DEBUG')
        ->set('defaultLocationChoice', 'León')
        ->set('unitsChoice', '°F')
        ->assertSet('unitsChoice', '°F')
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

it('persists appearance, locale, and individual notification preferences', function () {
    Native::fakeBridge();

    Native::visit('/settings')
        ->set('appearanceMode', 'Oscuro')
        ->set('localeChoice', 'English')
        ->set('notificationRecommendations', false)
        ->set('notificationRapidChanges', false)
        ->set('notificationDailySummary', true)
        ->assertSet('appearanceMode', 'Oscuro')
        ->assertSet('localeChoice', 'English');

    expect(AppSetting::query()->whereKey('appearance_mode')->value('value'))->toBe('dark')
        ->and(AppSetting::query()->whereKey('app_locale')->value('value'))->toBe('en')
        ->and(AppSetting::query()->whereKey('notification_recommendations')->value('value'))->toBe('0')
        ->and(AppSetting::query()->whereKey('notification_rapid_changes')->value('value'))->toBe('0')
        ->and(AppSetting::query()->whereKey('notification_daily_summary')->value('value'))->toBe('1');
});

it('keeps locations in the automatic recommendation schedule without manual alerts', function () {
    $location = Location::factory()->default()->create(['name' => 'Finca sin alertas']);
    $bridge = Native::fakeBridge();

    Native::visit('/settings')
        ->set('notificationRecommendations', false);

    $bridge->assertCalled('ClimateNotifications.SyncSchedule', function (array $parameters) use ($location): bool {
        return count($parameters['locations']) === 1
            && $parameters['locations'][0]['id'] === $location->id
            && $parameters['locations'][0]['alerts'] === []
            && isset($parameters['recommendationRules']);
    });
});
