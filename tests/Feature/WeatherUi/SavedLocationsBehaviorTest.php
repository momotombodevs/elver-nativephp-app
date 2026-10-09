<?php

use App\Domain\Weather\Contracts\WeatherProvider;
use App\Models\Location;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('explains that locations are captured from the device GPS', function () {
    Native::fakeBridge();

    Native::visit('/locations/add')
        ->assertSee('Ej. Casa, trabajo o finca')
        ->assertSee('Nombre del lugar')
        ->assertSee('Ubicación de este dispositivo')
        ->assertSee('Usaremos el GPS para guardar el punto y consultar su clima.')
        ->assertDontSee('Buscar ciudad o lugar')
        ->assertDontSee('Busca una ciudad o lugar');
});

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

it('uses a localized structured swipe action and a full-screen native add flow', function () {
    $location = Location::factory()->default()->create();

    Native::fakeBridge();

    $screen = Native::visit('/locations')
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
        ->assertSet('locationListVersion', 0);

    $screen->tap('add-location-fab')
        ->followNavigation()
        ->assertSet('fullScreenAddLocation', true)
        ->assertElement('list', fn (array $node): bool => ($node['ref'] ?? null) === 'add-location-screen')
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
