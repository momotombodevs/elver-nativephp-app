<?php

use App\Domain\Weather\Data\WeatherData;
use App\Domain\Weather\Data\WeatherPoint;
use App\Domain\Weather\Enums\RecommendationPriority;
use App\Models\AppSetting;
use App\Services\Weather\WeatherRecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(LazilyRefreshDatabase::class);

it('returns heat, UV, rain, wind, and rapid-change recommendations in priority order', function () {
    $startsAt = CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC');
    $data = weatherRecommendationData($startsAt, [
        'temperature_2m' => 34.0,
        'relative_humidity_2m' => 78.0,
        'precipitation' => 0.0,
        'wind_speed_10m' => 42.0,
    ], [
        'apparent_temperature' => 38.0,
        'uv_index' => 8.0,
    ], [
        [
            'temperature_2m' => 34.0,
            'precipitation' => 1.0,
        ],
        [
            'temperature_2m' => 27.0,
            'precipitation' => 0.0,
        ],
    ]);

    $recommendations = app(WeatherRecommendationService::class)->recommendationsFor($data);

    expect(array_column(array_map(fn ($item) => $item->toArray(), $recommendations), 'code'))
        ->toContain('heat_humidity', 'uv_high', 'rain_soon', 'strong_wind', 'rapid_temperature_drop')
        ->and($recommendations[0]->priority)->toBe(RecommendationPriority::Critical)
        ->and($recommendations[0]->value)->toBe(8.0)
        ->and($recommendations[0]->unit)->toBeNull();
});

it('returns a localized stale-data recommendation without making a weather decision', function () {
    $startsAt = CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC');
    $data = weatherRecommendationData($startsAt);

    AppSetting::query()->updateOrCreate(['key' => 'app_locale'], ['value' => 'en']);
    app()->setLocale('en');

    $recommendations = app(WeatherRecommendationService::class)->recommendationsFor($data, stale: true);

    expect($recommendations)->toHaveCount(1)
        ->and($recommendations[0]->code)->toBe('stale_data')
        ->and($recommendations[0]->priority)->toBe(RecommendationPriority::Info)
        ->and($recommendations[0]->title)->toBe('Review saved data');
});

it('returns no recommendation when conditions do not require action', function () {
    $startsAt = CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC');

    $recommendations = app(WeatherRecommendationService::class)->recommendationsFor(weatherRecommendationData($startsAt));

    expect($recommendations)->toBe([]);
});

it('converts recommendation values and messages to Fahrenheit preferences', function () {
    $startsAt = CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC');
    $data = weatherRecommendationData($startsAt, [
        'temperature_2m' => 34.0,
        'relative_humidity_2m' => 78.0,
        'precipitation' => 0.0,
        'wind_speed_10m' => 8.0,
    ], ['apparent_temperature' => 36.0]);
    AppSetting::query()->updateOrCreate(['key' => 'weather_units'], ['value' => 'imperial']);

    $recommendation = collect(app(WeatherRecommendationService::class)->recommendationsFor($data))
        ->firstWhere('code', 'heat_humidity');

    expect($recommendation->unit)->toBe('°F')
        ->and($recommendation->message)->toContain('97');
});

function weatherRecommendationData(
    CarbonImmutable $startsAt,
    array $currentValues = [
        'temperature_2m' => 25.0,
        'relative_humidity_2m' => 55.0,
        'precipitation' => 0.0,
        'wind_speed_10m' => 10.0,
    ],
    array $currentDetails = [
        'apparent_temperature' => 25.0,
        'uv_index' => 3.0,
        'wind_direction_10m' => 0.0,
    ],
    array $hourlyOverrides = [],
): WeatherData {
    $hourly = array_map(
        fn (int $hour): WeatherPoint => new WeatherPoint(
            $startsAt->addHours($hour),
            array_merge([
                'temperature_2m' => 25.0,
                'relative_humidity_2m' => 55.0,
                'precipitation' => 0.0,
                'wind_speed_10m' => 10.0,
            ], $hourlyOverrides[$hour] ?? []),
        ),
        range(0, max(1, count($hourlyOverrides) - 1)),
    );

    return new WeatherData(
        timezone: 'America/Managua',
        current: new WeatherPoint($startsAt, $currentValues),
        hourly: $hourly,
        currentDetails: $currentDetails,
    );
}
