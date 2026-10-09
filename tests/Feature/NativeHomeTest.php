<?php

use App\Domain\Weather\Contracts\WeatherProvider;
use App\Jobs\RefreshLocationForecast;
use App\Models\AppSetting;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('renders an actionable empty climate summary after onboarding', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);

    Native::visit('/')
        ->assertSee('Sin ubicación')
        ->assertSee('Agrega una ubicación')
        ->assertSee('Agregar ubicación')
        ->assertDontSee('Actualizar')
        ->assertDontSee('PRINCIPAL')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-empty-state')
        ->assertAccessible();
});

it('keeps existing locations out of onboarding after an upgrade', function () {
    Location::factory()->default()->create(['name' => 'Finca El Sol']);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/')
        ->assertSee('Finca El Sol')
        ->assertDontSee('BIENVENIDO A ELVER');

    expect(AppSetting::query()->whereKey('onboarding_seen')->value('value'))->toBe('1');
});

it('keeps weather details out of the summary until a forecast is available', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $location = Location::factory()->default()->create();

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/')
        ->assertElement('activity_indicator', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-loading-state-indicator')
        ->assertDontSee('Más datos')
        ->assertDontSee('Se siente como')
        ->assertDontSee('PRINCIPAL')
        ->assertAccessible();

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
});

it('renders cached current conditions and a native chart', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $location = Location::factory()->default()->create(['name' => 'Finca El Sol']);
    WeatherSnapshot::factory()->create(['location_id' => $location->id]);

    Native::visit('/')
        ->assertSee('Finca El Sol')
        ->assertSee('Condiciones actuales')
        ->assertSee('Temperatura')
        ->assertSee('Lluvia · próximas 24 horas')
        ->assertSee('Sin datos de lluvia para este periodo.')
        ->assertSee('Más datos')
        ->assertSee('Se siente como')
        ->assertSee('Próximas 24 horas')
        ->assertSee('Actualizado')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-recommendations')
        ->assertDontSee('PRINCIPAL')
        ->assertElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'open-settings'
            && ($node['props']['label'] ?? null) === 'Ajustes')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-weather-details')
        ->assertElement('line_chart', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-temperature-chart'
            && ($node['props']['a11y_label'] ?? null) === 'Temperatura prevista para las próximas 24 horas en Finca El Sol')
        ->assertAccessible();
});

it('changes the summary location without changing the primary location', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $first = Location::factory()->default()->create(['name' => 'Managua']);
    $second = Location::factory()->create(['name' => 'León']);
    WeatherSnapshot::factory()->create(['location_id' => $first->id]);
    WeatherSnapshot::factory()->create(['location_id' => $second->id]);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/')
        ->assertSee('Managua')
        ->set('locationChoice', 'León')
        ->assertSet('locationId', $second->id)
        ->assertSee('León');

    expect($first->fresh()->is_default)->toBeTrue();
});

it('refreshes weather manually', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $location = Location::factory()->default()->create();
    WeatherSnapshot::factory()->create(['location_id' => $location->id]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/')
        ->call('refreshForecast')
        ->assertSet('loading', true);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === true
    );
});

it('renders cached conditions while refreshing stale weather in the queue', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $location = Location::factory()->default()->create(['name' => 'Finca El Sol']);
    WeatherSnapshot::factory()->expired()->create(['location_id' => $location->id]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    Native::visit('/')
        ->assertSee('Sin conexión')
        ->assertElement('activity_indicator', fn (array $node): bool => ($node['ref'] ?? null) === 'summary-loading-indicator')
        ->assertSet('loading', true)
        ->assertAccessible();

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );
});

it('automatically refreshes the summary after its forecast cache expires', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $location = Location::factory()->default()->create();
    WeatherSnapshot::factory()->create(['location_id' => $location->id]);

    $provider = Mockery::mock(WeatherProvider::class);
    $provider->shouldReceive('name')->andReturn('open-meteo');
    $provider->shouldNotReceive('fetch');
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    $screen = Native::visit('/')->assertSet('loading', false);
    Queue::assertNothingPushed();

    $this->travel(31)->minutes();

    $screen->firePoll('refreshForecastIfNeeded')->assertSet('loading', true);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->force === false
    );

    $this->travelBack();
});

it('updates the summary chart when a fresh cached forecast changes', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);
    $location = Location::factory()->default()->create();
    $snapshot = WeatherSnapshot::factory()->create(['location_id' => $location->id]);
    Queue::fake([RefreshLocationForecast::class]);

    $screen = Native::visit('/');
    $previousValue = $screen->get('temperatureSeries')[0]['points'][0]['value'];
    $payload = $snapshot->fresh()->payload;
    $payload['hourly'][0]['values']['temperature_2m'] += 5;
    $payload['current']['values']['temperature_2m'] += 5;
    $snapshot->update([
        'payload' => $payload,
        'fetched_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $screen->firePoll('refreshForecastIfNeeded');

    expect($screen->get('temperatureSeries')[0]['points'][0]['value'])->toBe($previousValue + 5)
        ->and($screen->get('forecast')['current']['values']['temperature_2m'])->toBe(33.5);

    Queue::assertNothingPushed();
});
