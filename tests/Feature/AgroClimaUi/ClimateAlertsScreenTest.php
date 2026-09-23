<?php

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Enums\AlertState;
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
        ->assertDontSee('Evaluar ahora')
        ->assertDontSee(substr($location->id, 0, 8))
        ->assertSet('showCreateSheet', false)
        ->tap('create-alert-action')
        ->assertSet('showCreateSheet', true)
        ->assertElement('bottom_sheet', fn (array $node): bool => ($node['ref'] ?? null) === 'create-alert-sheet')
        ->assertSee('Umbral (°C)')
        ->set('metricChoice', 'Precipitación')
        ->assertSee('Umbral (mm)')
        ->set('threshold', '12,5')
        ->tap('create-alert')
        ->assertSet('showCreateSheet', false)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params === [
            'message' => 'Alerta guardada.',
            'duration' => 'short',
        ]);

    expect(ClimateAlert::query()->sole()->threshold)->toBe('12.50');
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
        ->assertSee('Umbral superado')
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
        ->assertSee('Datos desactualizados')
        ->assertSee('Revisando las condiciones para tus alertas…')
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
        ->assertSee('Revisando las condiciones para tus alertas…')
        ->assertSet('forecastRefreshLoading', true);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
    expect(ClimateAlert::query()->sole()->enabled)->toBeTrue();
});
