<?php

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\Location;
use App\Services\AgroClima\ChartSeriesFactory;
use Carbon\CarbonImmutable;

function chartSeriesWeatherData(): WeatherData
{
    $at = CarbonImmutable::parse('2027-01-15T12:30:00Z');
    $point = fn (int $offset, float $temperature): WeatherPoint => new WeatherPoint(
        $at->startOfHour()->addHours($offset),
        [
            'temperature_2m' => $temperature,
            'relative_humidity_2m' => 70.0,
            'precipitation' => 0.0,
            'wind_speed_10m' => 8.0,
        ],
    );

    return new WeatherData(
        timezone: 'America/Managua',
        current: $point(0, 28),
        hourly: [$point(-1, 27), $point(0, 28), $point(1, 29), $point(24, 30)],
    );
}

it('keeps series and point identities stable across range updates', function () {
    $location = new Location;
    $location->id = '019d0000-0000-7000-8000-000000000001';
    $factory = new ChartSeriesFactory;

    $short = $factory->forMetric($location, chartSeriesWeatherData(), WeatherMetric::Temperature, 24);
    $long = $factory->forMetric($location, chartSeriesWeatherData(), WeatherMetric::Temperature, 48);

    expect($short[0]['id'])->toBe('location:019d0000-0000-7000-8000-000000000001:metric:temperature_2m');
    expect($short[0]['points'])->toHaveCount(2);
    expect($long[0]['points'])->toHaveCount(3);
    expect($short[0]['points'][0]['id'])->toBe($long[0]['points'][0]['id']);
    expect($short[0]['points'][0]['x'])->toBe('2027-01-15T12:00:00+00:00');
});

it('rejects chart ranges longer than the seven day forecast', function () {
    $location = new Location;
    $location->id = '019d0000-0000-7000-8000-000000000001';

    (new ChartSeriesFactory)->forMetric($location, chartSeriesWeatherData(), WeatherMetric::Temperature, 169);
})->throws(InvalidArgumentException::class, 'between 1 and 168 hours');
