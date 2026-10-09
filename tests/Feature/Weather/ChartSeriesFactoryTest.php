<?php

use App\Domain\Weather\Data\WeatherData;
use App\Domain\Weather\Data\WeatherPoint;
use App\Domain\Weather\Enums\WeatherMetric;
use App\Models\Location;
use App\Services\Weather\ChartSeriesFactory;
use Carbon\CarbonImmutable;

function chartSeriesWeatherData(): WeatherData
{
    $at = CarbonImmutable::parse('2027-01-15T12:30:00Z');
    $point = fn (int $offset, float $temperature, float $precipitation = 0.0): WeatherPoint => new WeatherPoint(
        $at->startOfHour()->addHours($offset),
        [
            'temperature_2m' => $temperature,
            'relative_humidity_2m' => 70.0,
            'precipitation' => $precipitation,
            'wind_speed_10m' => 8.0,
        ],
    );

    return new WeatherData(
        timezone: 'America/Managua',
        current: $point(0, 28),
        hourly: [$point(-1, 27), $point(0, 28, 0.5), $point(1, 29, 1.25), $point(24, 30, 3.0)],
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

it('aggregates weekly values by local calendar day', function () {
    $location = new Location;
    $location->id = '019d0000-0000-7000-8000-000000000001';
    $factory = new ChartSeriesFactory;
    $data = chartSeriesWeatherData();

    $temperatures = $factory->forMetricByDay($location, $data, WeatherMetric::Temperature)[0]['points'];
    $rainfall = $factory->forMetricByDay($location, $data, WeatherMetric::Precipitation)[0]['points'];

    expect($temperatures)->toHaveCount(2)
        ->and($temperatures[0]['x'])->toBe('2027-01-15')
        ->and($temperatures[0]['value'])->toBe(28.5)
        ->and($temperatures[1]['x'])->toBe('2027-01-16')
        ->and($temperatures[1]['value'])->toBe(30.0)
        ->and($rainfall[0]['value'])->toBe(1.75)
        ->and($rainfall[1]['value'])->toBe(3.0);
});

it('rejects weekly chart ranges longer than seven days', function () {
    $location = new Location;
    $location->id = '019d0000-0000-7000-8000-000000000001';

    (new ChartSeriesFactory)->forMetricByDay($location, chartSeriesWeatherData(), WeatherMetric::Temperature, 8);
})->throws(InvalidArgumentException::class, 'between 1 and 7 days');

it('uses the semantic accent foreground color for rain charts', function () {
    expect(WeatherMetric::Temperature->color())->toBe(theme('primary'))
        ->and(WeatherMetric::Humidity->color())->toBe('#9B72D8')
        ->and(WeatherMetric::Precipitation->color())->toBe(theme('accent-foreground'))
        ->and(WeatherMetric::Precipitation->label())->toBe('Lluvia')
        ->and(WeatherMetric::WindSpeed->color())->toBe('#55308A')
        ->and([
            WeatherMetric::Temperature->color(),
            WeatherMetric::Humidity->color(),
            WeatherMetric::Precipitation->color(),
            WeatherMetric::WindSpeed->color(),
        ])->toHaveCount(4);
});

it('exposes the branded native ui tokens in both appearances', function () {
    $theme = config('native-ui.theme');

    expect($theme['light'])->toMatchArray([
        'primary' => '#5B3A91',
        'accent' => '#E8DFF0',
        'on-accent' => '#382D43',
        'accent-foreground' => '#5B3A91',
        'background' => '#F5F3F7',
        'outline-variant' => '#E4DFE8',
    ])->and($theme['dark'])->toMatchArray([
        'primary' => '#B99DDD',
        'accent' => '#4B3B59',
        'on-accent' => '#F7EEFF',
        'accent-foreground' => '#DCC6F1',
        'background' => '#110D15',
        'outline-variant' => '#463B4E',
    ]);
});
