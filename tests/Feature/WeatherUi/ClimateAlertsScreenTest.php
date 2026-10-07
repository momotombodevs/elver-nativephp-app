<?php

use App\Domain\Weather\Contracts\WeatherProvider;
use App\Domain\Weather\Enums\AlertState;
use App\Domain\Weather\Enums\ThresholdOperator;
use App\Domain\Weather\Enums\WeatherMetric;
use App\Events\ClimateNotificationPermissionResult;
use App\Jobs\RefreshLocationForecast;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('keeps alerts primary and creates one from a native bottom sheet', function () {
    $location = Location::factory()->default()->create(['name' => 'Invernadero']);
    WeatherSnapshot::factory()->create(['location_id' => $location->id]);

    Native::fakeBridge();

    $screen = Native::visit('/alerts')
        ->assertSee('Tus alertas')
        ->assertSee('Sin alertas')
        ->assertDontSee('Avisos aunque cierres la app')
        ->assertDontSee('Activa notificaciones para recibir avisos.')
        ->assertSee('Revisando permisos…')
        ->assertNativeCalled('ClimateNotifications.CheckPermission')
        ->assertNativeNotCalled('ClimateNotifications.SyncSchedule')
        ->assertDontSee('Evaluar ahora')
        ->assertDontSee(substr($location->id, 0, 8))
        ->assertSet('showCreateSheet', false)
        ->tap('create-alert-action')
        ->assertSet('showCreateSheet', true)
        ->assertElement('bottom_sheet', fn (array $node): bool => ($node['ref'] ?? null) === 'create-alert-sheet')
        ->assertSee('Qué medir')
        ->assertSee('Avisar si')
        ->assertSee('Valor (°C)')
        ->tap('quick-alert-rain')
        ->assertSet('metricChoice', 'Lluvia')
        ->assertSet('threshold', '')
        ->assertSee('Valor (mm)')
        ->set('threshold', '12,5')
        ->tap('create-alert')
        ->assertSet('showCreateSheet', false)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params === [
            'message' => 'Alerta guardada.',
            'duration' => 'short',
        ])
        ->assertNativeCalled('ClimateNotifications.RequestPermission')
        ->assertSee('Revisando permisos…')
        ->assertSee('Lluvia')
        ->assertSee('Tus alertas');

    $alert = ClimateAlert::query()->sole();
    expect($alert->threshold)->toBe('12.50')
        ->and($alert->metric)->toBe(WeatherMetric::Precipitation)
        ->and($alert->operator)->toBe(ThresholdOperator::Above);
});

it('refreshes notification access when the alerts screen opens and resumes', function () {
    Location::factory()->default()->create(['name' => 'Mi ubicación']);

    Native::fakeBridge();

    $screen = Native::visit('/alerts')
        ->assertNativeCalled('ClimateNotifications.CheckPermission')
        ->assertSee('Revisando permisos…');
    $firstRequestId = $screen->get('pendingNotificationAccessId');

    $screen->emitNative(ClimateNotificationPermissionResult::class, [
        'granted' => true,
        'id' => $firstRequestId,
    ])->assertSee('Avisos activados')
        ->assertDontSee('Activar');

    $screen->call('onResume')
        ->assertNativeCalled('ClimateNotifications.CheckPermission', fn (array $parameters): bool => $parameters['id'] !== $firstRequestId
        );
});

it('shows only the enabled status after notification access is granted', function () {
    Location::factory()->default()->create(['name' => 'Mi ubicación']);

    Native::fakeBridge();

    $screen = Native::visit('/alerts');
    $requestId = $screen->get('pendingNotificationAccessId');

    $screen->emitNative(ClimateNotificationPermissionResult::class, [
        'granted' => true,
        'id' => $requestId,
    ])->assertSee('Avisos activados')
        ->assertDontSee('Activa los avisos')
        ->assertDontSee('Activar')
        ->assertDontSee('Abrir sistema');
});

it('offers activation instructions when notification access is not granted', function () {
    Location::factory()->default()->create(['name' => 'Mi ubicación']);

    Native::fakeBridge();

    $screen = Native::visit('/alerts');
    $requestId = $screen->get('pendingNotificationAccessId');

    $screen->emitNative(ClimateNotificationPermissionResult::class, [
        'granted' => false,
        'id' => $requestId,
    ])->assertSee('Activa los avisos')
        ->assertSee('Activa los avisos en Ajustes.')
        ->assertSee('Activar')
        ->assertSee('Abrir sistema')
        ->assertDontSee('Avisos aunque cierres la app');
});

it('fills alert type and comparison for quick choices without choosing a threshold', function () {
    Location::factory()->default()->create();

    Native::fakeBridge();

    Native::visit('/alerts')
        ->tap('create-alert-action')
        ->set('threshold', '12')
        ->tap('quick-alert-rain')
        ->assertSet('metricChoice', 'Lluvia')
        ->assertSet('operatorChoice', 'Más de')
        ->assertSet('threshold', '')
        ->set('threshold', '30')
        ->tap('quick-alert-heat')
        ->assertSet('metricChoice', 'Temperatura')
        ->assertSet('operatorChoice', 'Más de')
        ->assertSet('threshold', '')
        ->set('threshold', '10')
        ->tap('quick-alert-cold')
        ->assertSet('metricChoice', 'Temperatura')
        ->assertSet('operatorChoice', 'Menos de')
        ->assertSet('threshold', '')
        ->set('threshold', '8')
        ->tap('quick-alert-wind')
        ->assertSet('metricChoice', 'Viento')
        ->assertSet('operatorChoice', 'Más de')
        ->assertSet('threshold', '');
});

it('uses a native control for pause and confirms destructive deletion', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela norte']);
    $alert = ClimateAlert::factory()->create([
        'location_id' => $location->id,
        'last_state' => AlertState::Exceeded,
    ]);

    Native::fakeBridge();

    $screen = Native::visit('/alerts')
        ->assertSee('Activa')
        ->assertSee('Superó el valor')
        ->tap('toggle-alert-'.$alert->id)
        ->assertSee('Pausada')
        ->tap('toggle-alert-'.$alert->id)
        ->assertSee('Activa')
        ->assertElement('list_item', fn (array $node): bool => ($node['ref'] ?? null) === 'toggle-alert-'.$alert->id
            && ($node['props']['trailing_type'] ?? null) === 'checkbox'
            && ($node['props']['trailing_checked'] ?? null) === true
            && str_contains((string) ($node['props']['trailing_actions_json'] ?? ''), 'Eliminar'))
        ->assertAccessible()
        ->call('setAlertEnabled', $alert->id, false)
        ->assertSee('Pausada')
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Alerta pausada.')
        ->longPress('toggle-alert-'.$alert->id)
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Eliminar alerta'
            && $params['id'] === 'delete-alert:'.$alert->id)
        ->emitNative(ButtonPressed::class, [
            'index' => 1,
            'label' => 'Eliminar',
            'id' => 'delete-alert:'.$alert->id,
        ])
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Alerta eliminada.');

    expect($alert->fresh())->toBeNull();
});

it('shows the location, threshold, state, and last activation for every alert', function () {
    $location = Location::factory()->default()->create(['name' => 'San Juan del Sur']);
    ClimateAlert::factory()->create([
        'location_id' => $location->id,
        'last_state' => AlertState::Exceeded,
        'last_triggered_at' => now()->subHour(),
    ]);

    Native::fakeBridge();

    Native::visit('/alerts')
        ->assertSee('San Juan del Sur')
        ->assertSee('Superó el valor')
        ->assertSee('Última activación:')
        ->assertAccessible();
});

it('disambiguates duplicate location names without exposing internal identifiers', function () {
    $first = Location::factory()->default()->create([
        'name' => 'Mi ubicación',
        'latitude' => 12.1185,
        'longitude' => -86.2240,
    ]);
    $second = Location::factory()->create([
        'name' => 'Mi ubicación',
        'latitude' => 12.1190,
        'longitude' => -86.2250,
    ]);

    Native::visit('/alerts')
        ->assertSee('Mi ubicación · 12.1185, -86.2240')
        ->assertSee('Mi ubicación · 12.1190, -86.2250')
        ->assertDontSee(substr($first->id, 0, 8))
        ->assertDontSee(substr($second->id, 0, 8))
        ->assertAccessible();
});

it('evaluates stale alert data from cache while refreshing in the queue', function () {
    $location = Location::factory()->default()->create(['name' => 'Invernadero']);
    WeatherSnapshot::factory()->expired()->create(['location_id' => $location->id]);
    ClimateAlert::factory()->create(['location_id' => $location->id]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);
    Native::fakeBridge();

    Native::visit('/alerts')
        ->assertSee('Datos antiguos')
        ->assertSee('Actualizando clima…')
        ->assertSet('forecastRefreshLoading', true)
        ->assertAccessible();

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
});

it('queues the first weather review when creating an alert without cached data', function () {
    $location = Location::factory()->default()->create(['name' => 'Invernadero']);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);
    Native::fakeBridge();

    Native::visit('/alerts')
        ->tap('create-alert-action')
        ->set('threshold', '30')
        ->tap('create-alert')
        ->assertSee('Actualizando clima…')
        ->assertSet('forecastRefreshLoading', true);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
    expect(ClimateAlert::query()->sole()->enabled)->toBeTrue();
});
