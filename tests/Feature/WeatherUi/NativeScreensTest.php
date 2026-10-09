<?php

use App\Domain\Weather\Contracts\WeatherProvider;
use App\Domain\Weather\Data\WeatherData;
use App\Domain\Weather\Data\WeatherPoint;
use App\Jobs\RefreshLocationForecast;
use App\Models\AppSetting;
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

it('registers all v1 screens with shared three-item native navigation', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    foreach (['/' => 'Consulta el clima de tu zona.', '/explorer' => 'Sin datos', '/locations' => 'Guarda un lugar para ver su clima.', '/alerts' => 'Después podrás crear alertas.', '/settings' => 'Tema: Sistema'] as $uri => $copy) {
        $screen = Native::visit($uri)
            ->assertSee($copy)
            ->assertSee('Resumen')
            ->assertSee('Gráficas')
            ->assertSee('Ubicaciones');

        if (in_array($uri, ['/settings', '/alerts'], true)) {
            $screen->assertTabBarHidden();
        } else {
            $screen->assertTabBarVisible()
                ->assertHasTab('Resumen')
                ->assertHasTab('Gráficas')
                ->assertHasTab('Ubicaciones')
                ->assertMissingElement('bottom_nav_item', fn (array $node): bool => ($node['props']['label'] ?? null) === 'Alertas');
        }
    }
});

it('renders hourly and daily chart points for their selected ranges', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    WeatherSnapshot::factory()->create([
        'location_id' => $location->id,
        'payload' => weatherUiWeatherData()->toArray(),
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

    expect($screen->get('series')[0]['points'])->toHaveCount(3)
        ->and($screen->get('series')[0]['points'][0]['x'])->toBe('2026-09-16')
        ->and($screen->get('chartXAxis')['type'])->toBe('date')
        ->and($screen->get('selectedPointId'))->toBeNull();
});

it('refreshes the chart manually', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    WeatherSnapshot::factory()->create(['location_id' => $location->id]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/explorer')
        ->call('refreshSeries')
        ->assertSet('loading', true);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === true
    );
});

it('renders cached chart data while refreshing stale weather in the queue', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    WeatherSnapshot::factory()->expired()->create([
        'location_id' => $location->id,
        'payload' => weatherUiWeatherData()->toArray(),
    ]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/explorer')
        ->assertSee('Sin conexión')
        ->assertElement('activity_indicator', fn (array $node): bool => ($node['ref'] ?? null) === 'explorer-loading-indicator')
        ->assertSet('loading', true)
        ->assertAccessible();

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
});

it('automatically refreshes the chart after its forecast cache expires', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    WeatherSnapshot::factory()->create([
        'location_id' => $location->id,
        'payload' => weatherUiWeatherData()->toArray(),
    ]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    $screen = Native::visit('/explorer')->assertSet('loading', false);
    Queue::assertNothingPushed();

    $this->travel(31)->minutes();

    $screen->firePoll('refreshForecastIfNeeded')->assertSet('loading', true);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );

    $this->travelBack();
});

it('updates the explorer chart when a fresh cached forecast changes', function () {
    $location = Location::factory()->default()->create(['name' => 'Parcela Central']);
    $snapshot = WeatherSnapshot::factory()->create([
        'location_id' => $location->id,
        'payload' => weatherUiWeatherData()->toArray(),
    ]);
    Queue::fake([RefreshLocationForecast::class]);

    $screen = Native::visit('/explorer');
    $previousValue = $screen->get('series')[0]['points'][0]['value'];
    $payload = $snapshot->fresh()->payload;
    $payload['hourly'][0]['values']['temperature_2m'] += 5;
    $snapshot->update([
        'payload' => $payload,
        'fetched_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $screen->firePoll('refreshForecastIfNeeded');

    expect($screen->get('series')[0]['points'][0]['value'])->toBe($previousValue + 5);

    Queue::assertNothingPushed();
});

it('saves a GPS result locally and makes the first location principal', function () {
    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);

    $bridge = Native::fakeBridge()
        ->respondTo('Geolocation.RequestPermissions', [])
        ->respondTo('Geolocation.GetCurrentPosition', []);

    $screen = Native::visit('/locations/add')
        ->assertAccessible()
        ->assertSet('fullScreenAddLocation', true)
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

    $screen = Native::visit('/locations/add')
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
        'payload' => weatherUiWeatherData()->toArray(),
    ]);

    Native::fakeBridge();

    $screen = Native::visit('/alerts')
        ->tap('create-alert-action')
        ->followNavigation()
        ->set('threshold', '30,5')
        ->tap('create-alert')
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Alerta guardada.')
        ->goBack()
        ->assertSee('Temperatura')
        ->assertSee('más de 30,5 °C');

    $alert = ClimateAlert::query()->sole();
    expect($alert->enabled)->toBeTrue();

    $screen->tap('toggle-alert-'.$alert->id)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Alerta pausada.');

    expect($alert->refresh()->enabled)->toBeFalse();
});

it('keeps an empty climate alert open and does not save it', function () {
    Location::factory()->default()->create();

    $screen = Native::visit('/alerts/create')
        ->tap('create-alert')
        ->assertSet('showCreateSheet', true)
        ->assertSee('Escribe un número válido.');

    expect(ClimateAlert::query()->count())->toBe(0);
});

it('opens the location and highlights the alert from a notification route', function () {
    $location = Location::factory()->default()->create(['name' => 'Finca El Sol']);
    $alert = ClimateAlert::factory()->create(['location_id' => $location->id]);
    WeatherSnapshot::factory()->create([
        'location_id' => $location->id,
        'payload' => weatherUiWeatherData()->toArray(),
    ]);

    Native::fakeBridge();

    Native::visit('/alerts/location/'.$location->id.'/alert/'.$alert->id)
        ->assertSet('locationId', $location->id)
        ->assertSet('selectedAlertId', $alert->id)
        ->assertElement('list_item', fn (array $node): bool => ($node['ref'] ?? null) === 'toggle-alert-'.$alert->id);
});

function weatherUiWeatherData(): WeatherData
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
