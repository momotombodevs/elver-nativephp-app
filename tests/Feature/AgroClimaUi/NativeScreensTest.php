<?php

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Jobs\RefreshLocationForecast;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('registers all four MVP screens with shared native navigation', function () {
    foreach (['/' => 'Consulta el clima de tu zona.', '/explorer' => 'Sin datos', '/locations' => 'Guarda tu primera ubicación para consultar el clima local.', '/alerts' => 'Después podrás crear alertas.'] as $uri => $copy) {
        Native::visit($uri)
            ->assertSee($copy)
            ->assertSee('Resumen')
            ->assertSee('Gráficas')
            ->assertSee('Ubicaciones')
            ->assertSee('Alertas');
    }
});

it('renders the chart explorer and preserves stable point identity in its series', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    WeatherSnapshot::factory()->create([
        'location_id' => $location->id,
        'payload' => agroclimaUiWeatherData()->toArray(),
    ]);

    $screen = Native::visit('/explorer')
        ->assertSee('Parcela Central')
        ->assertDontSee(substr($location->id, 0, 8))
        ->assertElement('line_chart', fn (array $node): bool => ($node['ref'] ?? null) === 'climate-line-chart')
        ->assertAccessible();

    $firstPointId = $screen->get('series')[0]['points'][0]['id'];
    $firstPoint = $screen->get('series')[0]['points'][0];

    $screen->call('pointSelected', json_encode([
        'version' => 1,
        'chart_type' => 'line',
        'series_id' => $screen->get('series')[0]['id'],
        'series_name' => 'Temperatura',
        'point_id' => $firstPointId,
        'point_index' => 0,
        'x_type' => 'datetime',
        'x' => $firstPoint['x'],
        'label' => $firstPoint['label'],
        'value' => $firstPoint['value'],
        'localized_value' => '28',
    ], JSON_THROW_ON_ERROR))
        ->assertSet('selectedPointId', $firstPointId)
        ->assertSee('Selección')
        ->assertSee('28 °C');

    $screen->set('rangeChoice', '7 días');

    expect($screen->get('series')[0]['points'][0]['id'])->toBe($firstPointId);
});

it('renders cached chart data while refreshing stale weather in the queue', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    WeatherSnapshot::factory()->expired()->create([
        'location_id' => $location->id,
        'payload' => agroclimaUiWeatherData()->toArray(),
    ]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/explorer')
        ->assertSee('Sin conexión')
        ->assertSee('Actualizando datos…')
        ->assertSet('loading', true)
        ->assertAccessible();

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
});

it('saves a GPS result locally and makes the first location principal', function () {
    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);

    $bridge = Native::fakeBridge()
        ->respondTo('Geolocation.RequestPermissions', [])
        ->respondTo('Geolocation.GetCurrentPosition', []);

    $screen = Native::visit('/locations')
        ->assertSet('showAddLocation', false)
        ->assertAccessible()
        ->tap('locations-add-first')
        ->assertSet('showAddLocation', true)
        ->assertSee('Agregar ubicación')
        ->assertDontSee('Cancelar')
        ->assertAccessible()
        ->set('name', 'Lote Este')
        ->tap('use-current-location');

    $permissionRequestId = $screen->get('pendingPermissionRequestId');

    $screen->emitNative(PermissionRequestResult::class, [
        'location' => 'granted',
        'coarseLocation' => 'granted',
        'fineLocation' => 'granted',
        'error' => null,
        'id' => $permissionRequestId,
    ]);

    $requestId = $screen->get('pendingRequestId');

    $screen->emitNative(LocationReceived::class, [
        'success' => true,
        'latitude' => 12.1364,
        'longitude' => -86.2514,
        'accuracy' => 8.0,
        'timestamp' => 1_800_000_000,
        'provider' => 'gps',
        'error' => null,
        'id' => $requestId,
    ])->assertSet('showAddLocation', false)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params === [
            'message' => 'Lote Este se guardó.',
            'duration' => 'short',
        ]);

    $bridge->assertCalled('Geolocation.GetCurrentPosition', fn (array $params): bool => $params['id'] === $requestId
        && $params['fineAccuracy'] === true);
    $bridge->assertCalled('Geolocation.RequestPermissions', fn (array $params): bool => $params['id'] === $permissionRequestId);

    $location = Location::query()->sole();
    expect($location->name)->toBe('Lote Este')
        ->and($location->is_default)->toBeTrue();

    $provider->shouldNotHaveReceived('fetch');
});

it('opens app settings after location permission is permanently denied', function () {
    $bridge = Native::fakeBridge()
        ->respondTo('Geolocation.RequestPermissions', [])
        ->respondTo('System.OpenAppSettings', []);

    $screen = Native::visit('/locations')
        ->tap('locations-add-first')
        ->tap('use-current-location');

    $permissionRequestId = $screen->get('pendingPermissionRequestId');

    $screen->emitNative(PermissionRequestResult::class, [
        'location' => 'permanently_denied',
        'coarseLocation' => 'permanently_denied',
        'fineLocation' => 'permanently_denied',
        'error' => null,
        'id' => $permissionRequestId,
    ])->assertSet('locating', false)
        ->assertSee('Activa la ubicación para Elver desde Ajustes.')
        ->assertSee('Abrir Ajustes')
        ->assertDontSee('Cancelar')
        ->tap('open-location-settings');

    $bridge->assertCalled('System.OpenAppSettings');
});

it('uses native location rows for primary selection and deletion', function () {
    $primary = Location::factory()->default()->create([
        'name' => 'Finca principal',
        'sort_order' => 1,
    ]);
    $secondary = Location::factory()->create([
        'name' => 'Lote norte',
        'is_default' => false,
        'sort_order' => 2,
    ]);

    $screen = Native::visit('/locations')
        ->assertElement('list')
        ->assertElement('list_item', fn (array $node): bool => ($node['ref'] ?? null) === 'location-'.$primary->id)
        ->assertElement('list_item', fn (array $node): bool => ($node['ref'] ?? null) === 'location-'.$secondary->id)
        ->assertAccessible()
        ->tap('location-'.$secondary->id)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Ubicación principal actualizada.');

    expect($primary->refresh()->is_default)->toBeFalse()
        ->and($secondary->refresh()->is_default)->toBeTrue();

    $screen->call('deleteLocation', $secondary->id);

    $screen->emitNative(ButtonPressed::class, [
        'index' => 1,
        'label' => 'Eliminar',
        'id' => $screen->get('pendingDeleteAlertId'),
    ])->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Ubicación eliminada.');

    expect($secondary->fresh())->toBeNull()
        ->and($primary->refresh()->is_default)->toBeTrue();
});

it('creates and pauses a local climate threshold', function () {
    $location = Location::factory()->default()->create(['name' => 'Invernadero']);
    WeatherSnapshot::factory()->create([
        'location_id' => $location->id,
        'payload' => agroclimaUiWeatherData()->toArray(),
    ]);

    Native::fakeBridge();

    $screen = Native::visit('/alerts')
        ->tap('create-alert-action')
        ->set('threshold', '30,5')
        ->tap('create-alert')
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Alerta guardada.')
        ->assertSee('Temperatura')
        ->assertSee('mayor que 30,5 °C');

    $alert = ClimateAlert::query()->sole();
    expect($alert->enabled)->toBeTrue();

    $screen->tap('toggle-alert-'.$alert->id)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Alerta pausada.');

    expect($alert->refresh()->enabled)->toBeFalse();
});

function agroclimaUiWeatherData(): WeatherData
{
    $startsAt = CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC');
    $values = fn (int $hour): array => [
        'temperature_2m' => 28.0 + ($hour / 10),
        'relative_humidity_2m' => 72.0 - $hour,
        'precipitation' => $hour % 4 === 0 ? 1.5 : 0.0,
        'wind_speed_10m' => 8.0 + $hour,
    ];

    return new WeatherData(
        timezone: 'America/Managua',
        current: new WeatherPoint($startsAt, $values(0)),
        hourly: collect(range(0, 47))
            ->map(fn (int $hour): WeatherPoint => new WeatherPoint($startsAt->addHours($hour), $values($hour)))
            ->all(),
    );
}
