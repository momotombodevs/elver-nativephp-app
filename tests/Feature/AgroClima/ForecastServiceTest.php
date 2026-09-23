<?php

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Domain\AgroClima\Exceptions\WeatherUnavailableException;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use App\Services\AgroClima\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function forecastServiceWeatherData(float $temperature = 30): WeatherData
{
    $at = CarbonImmutable::parse('2027-01-15T12:00:00Z');

    return new WeatherData(
        timezone: 'America/Managua',
        current: new WeatherPoint($at, [
            'temperature_2m' => $temperature,
            'relative_humidity_2m' => 70.0,
            'precipitation' => 0.0,
            'wind_speed_10m' => 8.0,
        ]),
        hourly: [],
    );
}

it('returns a fresh SQLite snapshot without calling the provider', function () {
    $this->travelTo('2027-01-15 12:10:00');
    $location = Location::factory()->create();
    WeatherSnapshot::factory()->for($location)->create([
        'payload' => forecastServiceWeatherData(29)->toArray(),
        'fetched_at' => now()->subMinutes(10),
        'expires_at' => now()->addMinutes(20),
    ]);
    $provider = new class implements WeatherProvider
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'open-meteo';
        }

        public function fetch(Coordinates $coordinates): WeatherData
        {
            $this->calls++;

            return forecastServiceWeatherData();
        }
    };

    $result = (new ForecastService($provider))->forLocation($location);

    expect($result->stale)->toBeFalse();
    expect($result->data->current->values['temperature_2m'])->toBe(29.0);
    expect($provider->calls)->toBe(0);
});

it('serves the last valid snapshot as stale when refresh fails', function () {
    $this->travelTo('2027-01-15 13:00:00');
    $location = Location::factory()->create();
    WeatherSnapshot::factory()->expired()->for($location)->create([
        'payload' => forecastServiceWeatherData(27)->toArray(),
    ]);
    $provider = new class implements WeatherProvider
    {
        public function name(): string
        {
            return 'open-meteo';
        }

        public function fetch(Coordinates $coordinates): WeatherData
        {
            throw new RuntimeException('offline');
        }
    };

    $result = (new ForecastService($provider))->forLocation($location);

    expect($result->stale)->toBeTrue();
    expect($result->data->current->values['temperature_2m'])->toBe(27.0);
});

it('persists a successful refresh and updates the resolved timezone', function () {
    $this->travelTo('2027-01-15 13:00:00');
    config()->set('services.open_meteo.cache_minutes', 45);
    $location = Location::factory()->create(['timezone' => 'UTC']);
    $provider = new class implements WeatherProvider
    {
        public function name(): string
        {
            return 'open-meteo';
        }

        public function fetch(Coordinates $coordinates): WeatherData
        {
            return forecastServiceWeatherData(31);
        }
    };

    $result = (new ForecastService($provider))->forLocation($location, force: true);

    expect($result->stale)->toBeFalse();
    expect($result->expiresAt->toIso8601String())->toBe('2027-01-15T13:45:00+00:00');
    expect($location->refresh()->timezone)->toBe('America/Managua');
    expect(WeatherSnapshot::query()->whereBelongsTo($location)->count())->toBe(1);
    expect((float) WeatherSnapshot::query()->firstOrFail()->payload['current']['values']['temperature_2m'])->toBe(31.0);
});

it('reports unavailability when neither remote nor cached data exists', function () {
    $location = Location::factory()->create();
    $provider = new class implements WeatherProvider
    {
        public function name(): string
        {
            return 'open-meteo';
        }

        public function fetch(Coordinates $coordinates): WeatherData
        {
            throw new RuntimeException('offline');
        }
    };

    (new ForecastService($provider))->forLocation($location);
})->throws(WeatherUnavailableException::class, 'no hay datos guardados');
