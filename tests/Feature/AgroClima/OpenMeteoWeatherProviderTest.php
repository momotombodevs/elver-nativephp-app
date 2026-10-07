<?php

use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Data\WeatherData;
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
            'utc_offset_seconds' => -21600,
            'current' => [
                'time' => 1_800_000_000,
                'temperature_2m' => 28.5,
                'relative_humidity_2m' => 73,
                'precipitation' => 0,
                'wind_speed_10m' => 12.4,
                'apparent_temperature' => 29.2,
                'uv_index' => 6.4,
                'wind_direction_10m' => 135,
            ],
            'hourly' => [
                'time' => [1_800_000_000, 1_800_003_600],
                'temperature_2m' => [28.5, 29.1],
                'relative_humidity_2m' => [73, 70],
                'precipitation' => [0, 0.2],
                'wind_speed_10m' => [12.4, 13.0],
            ],
            'daily' => [
                'time' => [1790661600, 1790748000],
                'temperature_2m_max' => [31.5, 32.0],
                'temperature_2m_min' => [23.1, 23.4],
                'uv_index_max' => [8.0, 8.4],
            ],
        ]),
    ]);

    $weather = (new OpenMeteoWeatherProvider)->fetch(new Coordinates(12.114, -86.236));

    expect($weather->timezone)->toBe('America/Managua');
    expect($weather->currentValue(WeatherMetric::Temperature))->toBe(28.5);
    expect($weather->currentDetails['apparent_temperature'])->toBe(29.2)
        ->and($weather->currentDetails['uv_index'])->toBe(6.4)
        ->and($weather->currentDetails['wind_direction_10m'])->toBe(135.0);
    expect($weather->hourly)->toHaveCount(2);
    expect($weather->hourly[1]->value(WeatherMetric::Precipitation))->toBe(0.2);
    expect($weather->daily[0]->maximumTemperature)->toBe(31.5)
        ->and($weather->daily[0]->minimumTemperature)->toBe(23.1)
        ->and($weather->daily[0]->maximumUvIndex)->toBe(8.0)
        ->and($weather->daily[0]->date->format('Y-m-d'))->toBe('2026-09-29');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.open-meteo.test/v1/forecast?'.http_build_query([
        'latitude' => 12.114,
        'longitude' => -86.236,
        'current' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,apparent_temperature,uv_index,wind_direction_10m',
        'hourly' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m',
        'daily' => 'temperature_2m_max,temperature_2m_min,uv_index_max',
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
            'utc_offset_seconds' => 0,
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

it('applies the timezone offset to Unix daily timestamps', function () {
    config()->set('services.open_meteo.url', 'https://api.open-meteo.test/v1/forecast');
    Http::preventStrayRequests();
    Http::fake([
        'api.open-meteo.test/v1/forecast*' => Http::response([
            'timezone' => 'Asia/Dhaka',
            'utc_offset_seconds' => 21600,
            'current' => [
                'time' => 1_790_620_800,
                'temperature_2m' => 28,
                'relative_humidity_2m' => 70,
                'precipitation' => 0,
                'wind_speed_10m' => 10,
            ],
            'hourly' => [
                'time' => [1_790_620_800],
                'temperature_2m' => [28],
                'relative_humidity_2m' => [70],
                'precipitation' => [0],
                'wind_speed_10m' => [10],
            ],
            'daily' => [
                'time' => [1_790_618_400],
                'temperature_2m_max' => [31],
                'temperature_2m_min' => [25],
                'uv_index_max' => [8],
            ],
        ]),
    ]);

    $weather = (new OpenMeteoWeatherProvider)->fetch(new Coordinates(0, 0));

    expect($weather->daily[0]->date->format('Y-m-d'))->toBe('2026-09-29');
});

it('loads snapshots created before the auxiliary forecast fields existed', function () {
    $weather = WeatherData::fromArray([
        'timezone' => 'America/Managua',
        'current' => [
            'at' => '2026-09-29T12:00:00+00:00',
            'values' => [
                'temperature_2m' => 28.0,
                'relative_humidity_2m' => 70.0,
                'precipitation' => 0.0,
                'wind_speed_10m' => 10.0,
            ],
        ],
        'hourly' => [],
    ]);

    expect($weather->currentDetails)->toBe([])
        ->and($weather->daily)->toBe([]);
});
