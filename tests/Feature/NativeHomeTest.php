<?php

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Jobs\RefreshLocationForecast;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('renders an actionable empty climate summary', function () {
    Native::visit('/')
        ->assertSee('Elver')
        ->assertSee('Agrega una ubicación')
        ->assertSee('Agregar ubicación')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-empty-state')
        ->assertAccessible();
});

it('renders cached current conditions and a native chart', function () {
    $location = Location::factory()->default()->create(['name' => 'Finca El Sol']);
    WeatherSnapshot::factory()->create(['location_id' => $location->id]);

    Native::visit('/')
        ->assertSee('Finca El Sol')
        ->assertSee('Condiciones actuales')
        ->assertSee('Temperatura')
        ->assertSee('Próximas 24 horas')
        ->assertElement('line_chart', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-temperature-chart'
            && ($node['props']['a11y_label'] ?? null) === 'Temperatura prevista para las próximas 24 horas en Finca El Sol')
        ->assertAccessible();
});

it('renders cached conditions while refreshing stale weather in the queue', function () {
    $location = Location::factory()->default()->create(['name' => 'Finca El Sol']);
    WeatherSnapshot::factory()->expired()->create(['location_id' => $location->id]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/')
        ->assertSee('Sin conexión')
        ->assertSee('Actualizando…')
        ->assertSet('loading', true)
        ->assertAccessible();

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
});
