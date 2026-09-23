<?php

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Jobs\RefreshLocationForecast;
use App\Models\Location;
use App\Services\AgroClima\ForecastRefreshQueue;
use App\Services\AgroClima\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function refreshLocationForecastWeatherData(): WeatherData
{
    $at = CarbonImmutable::parse('2027-01-15T12:00:00Z');
    $values = [
        'temperature_2m' => 30.0,
        'relative_humidity_2m' => 70.0,
        'precipitation' => 0.0,
        'wind_speed_10m' => 8.0,
    ];

    return new WeatherData(
        timezone: 'America/Managua',
        current: new WeatherPoint($at, $values),
        hourly: [new WeatherPoint($at, $values)],
    );
}

it('persists weather and completes a queued forecast refresh', function () {
    $location = Location::factory()->create();
    $provider = new class implements WeatherProvider
    {
        public function name(): string
        {
            return 'open-meteo';
        }

        public function fetch(Coordinates $coordinates): WeatherData
        {
            return refreshLocationForecastWeatherData();
        }
    };
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    $refreshQueue = app(ForecastRefreshQueue::class);
    $requestId = $refreshQueue->start($location);

    Queue::assertPushed(RefreshLocationForecast::class, fn (RefreshLocationForecast $job): bool => $job->locationId === $location->id && $job->requestId === $requestId && $job->force === false
    );
    (new RefreshLocationForecast($location->id, $requestId, false))
        ->handle(app(ForecastService::class), $refreshQueue);

    expect($refreshQueue->status($requestId))->toBe('complete');
    $this->assertDatabaseHas('weather_snapshots', [
        'location_id' => $location->id,
        'provider' => 'open-meteo',
    ]);
});

it('marks a queued forecast refresh failed when no cached data is available', function () {
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
    app()->instance(WeatherProvider::class, $provider);
    Queue::fake([RefreshLocationForecast::class]);

    $refreshQueue = app(ForecastRefreshQueue::class);
    $requestId = $refreshQueue->start($location);
    (new RefreshLocationForecast($location->id, $requestId, false))
        ->handle(app(ForecastService::class), $refreshQueue);

    expect($refreshQueue->status($requestId))->toBe('failed');
    $this->assertDatabaseMissing('weather_snapshots', [
        'location_id' => $location->id,
        'provider' => 'open-meteo',
    ]);
});
