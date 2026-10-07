<?php

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Models\Location;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('keeps a nearby GPS result from creating a duplicate location', function () {
    $existing = Location::factory()->default()->create([
        'name' => 'Finca principal',
        'latitude' => 12.1185,
        'longitude' => -86.2240,
        'sort_order' => 1,
    ]);

    Native::fakeBridge();

    $screen = Native::visit('/locations')
        ->set('pendingRequestId', 'nearby-position')
        ->set('locating', true)
        ->emitNative(LocationReceived::class, [
            'success' => true,
            'latitude' => 12.11882,
            'longitude' => -86.2240,
            'accuracy' => 40.0,
            'timestamp' => 1_800_000_000,
            'provider' => 'gps',
            'error' => null,
            'id' => 'nearby-position',
        ])
        ->assertSet('locating', false)
        ->assertSet('showAddLocation', false)
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Ubicación cercana'
            && array_column($params['buttons'], 'label') === ['Cancelar', 'Usar existente', 'Actualizar ubicación']);

    $screen->emitNative(ButtonPressed::class, [
        'index' => 1,
        'label' => 'Usar existente',
        'id' => 'nearby-location:'.$existing->id,
    ])
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params === [
            'message' => 'Usando Finca principal.',
            'duration' => 'short',
        ]);

    expect(Location::query()->count())->toBe(1)
        ->and(Location::query()->sole()->is($existing))->toBeTrue();
});

it('saves a searched community with the farm name, coordinates, and timezone', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::response([
            'results' => [[
                'id' => 12345,
                'name' => 'Masaya',
                'admin1' => 'Masaya',
                'admin2' => 'Masaya',
                'country' => 'Nicaragua',
                'latitude' => 11.974,
                'longitude' => -86.094,
                'timezone' => 'America/Managua',
            ]],
        ]),
    ]);

    Native::fakeBridge();

    Native::visit('/locations')
        ->tap('locations-add-first')
        ->set('name', 'Finca San José')
        ->set('communityQuery', 'Masaya')
        ->assertSee('Masaya · Nicaragua')
        ->tap('community-12345')
        ->assertSet('showAddLocation', false)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Finca San José se guardó.');

    $location = Location::query()->sole();
    expect($location->name)->toBe('Finca San José')
        ->and((float) $location->latitude)->toBe(11.974)
        ->and((float) $location->longitude)->toBe(-86.094)
        ->and($location->timezone)->toBe('America/Managua')
        ->and($location->is_default)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'name=Masaya')
        && str_contains($request->url(), 'language=es')
        && str_contains($request->url(), 'count=5'));
});

it('shows a friendly empty result for a community search with no matches', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::response([]),
    ]);

    Native::fakeBridge();

    Native::visit('/locations')
        ->tap('locations-add-first')
        ->set('communityQuery', 'No existe')
        ->assertSee('No encontramos comunidades con ese nombre.');

    expect(Location::query()->count())->toBe(0);
});

it('shows a connection error when community search cannot reach Open-Meteo', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::failedConnection(),
    ]);

    Native::fakeBridge();

    Native::visit('/locations')
        ->tap('locations-add-first')
        ->set('communityQuery', 'Masaya')
        ->assertSee('No se pudo buscar. Revisa tu conexión.')
        ->assertDontSee('No encontramos comunidades con ese nombre.');

    expect(Location::query()->count())->toBe(0);
});

it('can update an existing nearby location instead of creating a duplicate', function () {
    $existing = Location::factory()->default()->create([
        'name' => 'Mi ubicación',
        'latitude' => 12.1185,
        'longitude' => -86.2240,
    ]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);

    Native::fakeBridge();

    Native::visit('/locations')
        ->set('name', 'Parcela actualizada')
        ->set('pendingRequestId', 'updated-position')
        ->set('locating', true)
        ->emitNative(LocationReceived::class, [
            'success' => true,
            'latitude' => 12.11882,
            'longitude' => -86.22412,
            'accuracy' => 40.0,
            'timestamp' => 1_800_000_000,
            'provider' => 'gps',
            'error' => null,
            'id' => 'updated-position',
        ])
        ->emitNative(ButtonPressed::class, [
            'index' => 2,
            'label' => 'Actualizar ubicación',
            'id' => 'nearby-location:'.$existing->id,
        ])
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params === [
            'message' => 'Ubicación actualizada.',
            'duration' => 'short',
        ]);

    expect(Location::query()->count())->toBe(1)
        ->and($existing->refresh()->name)->toBe('Parcela actualizada')
        ->and((float) $existing->latitude)->toBe(12.11882)
        ->and((float) $existing->longitude)->toBe(-86.22412);

    $provider->shouldNotHaveReceived('fetch');
});

it('uses a localized structured swipe action and a compact add sheet', function () {
    $location = Location::factory()->default()->create();

    Native::fakeBridge();

    Native::visit('/locations')
        ->assertElement('list_item', function (array $node) use ($location): bool {
            if (($node['ref'] ?? null) !== 'location-'.$location->id) {
                return false;
            }

            $actions = json_decode($node['props']['trailing_actions_json'] ?? '[]', true);

            return count($actions) === 1
                && $actions[0]['label'] === 'Eliminar'
                && $actions[0]['role'] === 'destructive'
                && $actions[0]['icon'] === 'delete'
                && ! array_key_exists('on_swipe_delete', $node['props']);
        })
        ->assertSet('locationListVersion', 0)
        ->tap('add-location-fab')
        ->assertSet('locationListVersion', 1)
        ->assertElement('bottom_sheet', fn (array $node): bool => ($node['props']['detents'] ?? null) === '0.4,large')
        ->assertDontSee('Cancelar')
        ->assertAccessible();
});

it('offers long press as an accessible alternative to the delete swipe', function () {
    $location = Location::factory()->default()->create(['name' => 'Finca principal']);

    Native::fakeBridge();

    Native::visit('/locations')
        ->longPress('location-'.$location->id)
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Eliminar ubicación');

    expect($location->fresh())->not->toBeNull();
});

it('requires native confirmation before deleting the primary location', function () {
    $primary = Location::factory()->default()->create([
        'name' => 'Finca principal',
        'sort_order' => 1,
    ]);
    $replacement = Location::factory()->create([
        'name' => 'Lote norte',
        'is_default' => false,
        'sort_order' => 2,
    ]);

    Native::fakeBridge();

    $screen = Native::visit('/locations')
        ->call('deleteLocation', $primary->id)
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Eliminar ubicación'
            && $params['buttons'] === [
                ['label' => 'Cancelar', 'style' => 'cancel'],
                ['label' => 'Eliminar', 'style' => 'destructive'],
            ]);

    expect($primary->fresh())->not->toBeNull();

    $alertId = $screen->get('pendingDeleteAlertId');

    $screen->emitNative(ButtonPressed::class, [
        'index' => 1,
        'label' => 'Eliminar',
        'id' => $alertId,
    ])->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params === [
        'message' => 'Ubicación eliminada.',
        'duration' => 'short',
    ]);

    expect($primary->fresh())->toBeNull()
        ->and($replacement->refresh()->is_default)->toBeTrue();
});

it('keeps the location when native deletion confirmation is cancelled', function () {
    $location = Location::factory()->default()->create();

    Native::fakeBridge();

    $screen = Native::visit('/locations')->call('deleteLocation', $location->id);
    $alertId = $screen->get('pendingDeleteAlertId');

    $screen->emitNative(ButtonPressed::class, [
        'index' => 0,
        'label' => 'Cancelar',
        'id' => $alertId,
    ]);

    expect($location->fresh())->not->toBeNull();
});
