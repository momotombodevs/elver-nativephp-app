<?php

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Domain\AgroClima\Enums\AlertState;
use App\Domain\AgroClima\Enums\ThresholdOperator;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Services\AgroClima\AlertEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function alertWeatherData(?float $temperature = 36): WeatherData
{
    return new WeatherData(
        timezone: 'America/Managua',
        current: new WeatherPoint(CarbonImmutable::parse('2027-01-15T12:00:00Z'), [
            'temperature_2m' => $temperature,
            'relative_humidity_2m' => null,
            'precipitation' => 0.0,
            'wind_speed_10m' => 8.0,
        ]),
        hourly: [],
    );
}

it('evaluates enabled thresholds and records a trigger only on transition', function () {
    $this->travelTo('2027-01-15 12:00:00');
    $location = Location::factory()->create();
    $temperature = ClimateAlert::factory()->for($location)->create([
        'metric' => WeatherMetric::Temperature,
        'operator' => ThresholdOperator::Above,
        'threshold' => 35,
    ]);
    $humidity = ClimateAlert::factory()->for($location)->create([
        'metric' => WeatherMetric::Humidity,
        'operator' => ThresholdOperator::Below,
        'threshold' => 40,
    ]);
    $disabled = ClimateAlert::factory()->disabled()->for($location)->create();

    (new AlertEvaluator)->evaluate($location, alertWeatherData());

    expect($temperature->refresh()->last_state)->toBe(AlertState::Exceeded);
    expect($temperature->last_triggered_at->toIso8601String())->toBe('2027-01-15T12:00:00+00:00');
    expect($humidity->refresh()->last_state)->toBe(AlertState::NoData);
    expect($disabled->refresh()->last_evaluated_at)->toBeNull();

    $this->travelTo('2027-01-15 12:05:00');
    (new AlertEvaluator)->evaluate($location, alertWeatherData(37));

    expect($temperature->refresh()->last_triggered_at->toIso8601String())->toBe('2027-01-15T12:00:00+00:00');
    expect($temperature->last_evaluated_at->toIso8601String())->toBe('2027-01-15T12:05:00+00:00');
});

it('marks enabled alerts stale instead of evaluating old values', function () {
    $location = Location::factory()->create();
    $alert = ClimateAlert::factory()->for($location)->create();

    (new AlertEvaluator)->evaluate($location, alertWeatherData(), stale: true);

    expect($alert->refresh()->last_state)->toBe(AlertState::Stale);
    expect($alert->last_triggered_at)->toBeNull();
});
