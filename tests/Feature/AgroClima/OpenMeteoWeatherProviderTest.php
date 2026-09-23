<?php

use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Services\AgroClima\OpenMeteoWeatherProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('maps the Open-Meteo forecast into typed weather data', function () {
    config()->set('services.open_meteo.url', 'https://api.open-meteo.test');
    Http::preventStrayRequests();
    Http::fake([
        'api.open-meteo.test/v1/forecast*' => Http::response([
            'timezone' => 'America/Managua',
            'current' => [
                'time' => 1_800_000_000,
                'temperature_2m' => 28.5,
                'relative_humidity_2m' => 73,
                'precipitation' => 0,
                'wind_speed_10m' => 12.4,
            ],
            'hourly' => [
                'time' => [1_800_000_000, 1_800_003_600],
                'temperature_2m' => [28.5, 29.1],
                'relative_humidity_2m' => [73, 70],
                'precipitation' => [0, 0.2],
                'wind_speed_10m' => [12.4, 13.0],
            ],
        ]),
    ]);

    $weather = (new OpenMeteoWeatherProvider)->fetch(new Coordinates(12.114, -86.236));

    expect($weather->timezone)->toBe('America/Managua');
    expect($weather->currentValue(WeatherMetric::Temperature))->toBe(28.5);
    expect($weather->hourly)->toHaveCount(2);
    expect($weather->hourly[1]->value(WeatherMetric::Precipitation))->toBe(0.2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.open-meteo.test/v1/forecast?'.http_build_query([
        'latitude' => 12.114,
        'longitude' => -86.236,
        'current' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m',
        'hourly' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m',
        'forecast_days' => 8,
        'timezone' => 'auto',
        'timeformat' => 'unixtime',
    ]));
});

it('rejects misaligned hourly data from Open-Meteo', function () {
    config()->set('services.open_meteo.url', 'https://api.open-meteo.test/v1/forecast');
    Http::preventStrayRequests();
    Http::fake([
        'api.open-meteo.test/v1/forecast*' => Http::response([
            'timezone' => 'UTC',
            'current' => [
                'time' => 1_800_000_000,
                'temperature_2m' => 28,
                'relative_humidity_2m' => 70,
                'precipitation' => 0,
                'wind_speed_10m' => 10,
            ],
            'hourly' => [
                'time' => [1_800_000_000, 1_800_003_600],
                'temperature_2m' => [28],
                'relative_humidity_2m' => [70, 68],
                'precipitation' => [0, 0],
                'wind_speed_10m' => [10, 11],
            ],
        ]),
    ]);

    (new OpenMeteoWeatherProvider)->fetch(new Coordinates(12.114, -86.236));
})->throws(InvalidArgumentException::class, 'temperature_2m are misaligned');
