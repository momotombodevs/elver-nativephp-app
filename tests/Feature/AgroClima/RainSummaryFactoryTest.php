<?php

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Services\AgroClima\RainSummaryFactory;
use Carbon\CarbonImmutable;

it('totals rain and groups rainy hours in the community timezone', function () {
    $precipitation = array_fill(0, 25, 0.0);
    $precipitation[0] = 9.0;
    $precipitation[2] = 0.4;
    $precipitation[3] = 0.6;
    $precipitation[6] = 1.0;

    $summary = (new RainSummaryFactory)->forNext24Hours(rainSummaryWeatherData($precipitation));

    expect($summary['total'])->toBe(2.0)
        ->and($summary['reportedHours'])->toBe(24)
        ->and($summary['partial'])->toBeFalse()
        ->and($summary['hasRain'])->toBeTrue()
        ->and($summary['periods'])->toBe(['07:00–09:00', '11:00–12:00']);
});

it('reports a complete dry forecast without rainy time ranges', function () {
    $summary = (new RainSummaryFactory)->forNext24Hours(rainSummaryWeatherData(array_fill(0, 25, 0.0)));

    expect($summary['total'])->toBe(0.0)
        ->and($summary['partial'])->toBeFalse()
        ->and($summary['noData'])->toBeFalse()
        ->and($summary['hasRain'])->toBeFalse()
        ->and($summary['periods'])->toBe([]);
});

it('marks incomplete rainfall as partial and keeps known rain hours', function () {
    $summary = (new RainSummaryFactory)->forNext24Hours(rainSummaryWeatherData([
        1 => 0.0,
        3 => 0.5,
    ]));

    expect($summary['total'])->toBe(0.5)
        ->and($summary['reportedHours'])->toBe(2)
        ->and($summary['partial'])->toBeTrue()
        ->and($summary['noData'])->toBeFalse()
        ->and($summary['hasRain'])->toBeTrue()
        ->and($summary['periods'])->toBe(['08:00–09:00']);
});

it('distinguishes a forecast with no hourly rainfall data', function () {
    $summary = (new RainSummaryFactory)->forNext24Hours(rainSummaryWeatherData([]));

    expect($summary['total'])->toBeNull()
        ->and($summary['reportedHours'])->toBe(0)
        ->and($summary['partial'])->toBeTrue()
        ->and($summary['noData'])->toBeTrue()
        ->and($summary['hasRain'])->toBeFalse()
        ->and($summary['periods'])->toBe([]);
});

/** @param array<int, float|null> $precipitation */
function rainSummaryWeatherData(array $precipitation): WeatherData
{
    $startsAt = CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC');
    $point = fn (CarbonImmutable $at, ?float $rain): WeatherPoint => new WeatherPoint($at, [
        'temperature_2m' => 28.0,
        'relative_humidity_2m' => 70.0,
        'precipitation' => $rain,
        'wind_speed_10m' => 8.0,
    ]);
    $hourly = [];

    for ($offset = 0; $offset < 25; $offset++) {
        if (array_key_exists($offset, $precipitation)) {
            $hourly[] = $point($startsAt->addHours($offset), $precipitation[$offset]);
        }
    }

    return new WeatherData(
        timezone: 'America/Managua',
        current: $point($startsAt->addMinutes(30), 0.0),
        hourly: $hourly,
    );
}
