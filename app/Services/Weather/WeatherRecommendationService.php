<?php

namespace App\Services\Weather;

use App\Domain\Weather\Data\WeatherData;
use App\Domain\Weather\Data\WeatherPoint;
use App\Domain\Weather\Data\WeatherRecommendation;
use App\Domain\Weather\Enums\RecommendationPriority;
use App\Domain\Weather\Enums\WeatherMetric;
use App\Services\Localization\LocalePreferences;
use Carbon\CarbonImmutable;

final class WeatherRecommendationService
{
    public function __construct(
        private readonly UnitPreferences $units,
        LocalePreferences $localePreferences,
    ) {
        $localePreferences->apply();
    }

    /** @return list<WeatherRecommendation> */
    public function recommendationsFor(WeatherData $data, bool $stale = false): array
    {
        $reference = $data->current->at;

        if ($stale) {
            return [$this->recommendation(
                code: 'stale_data',
                priority: RecommendationPriority::Info,
                title: __('weather.recommendations.stale.title'),
                message: __('weather.recommendations.stale.message'),
                metric: null,
                value: null,
                unit: null,
                validUntil: $reference->addHour(),
            )];
        }

        $recommendations = [];
        $temperature = $data->currentValue(WeatherMetric::Temperature);
        $humidity = $data->currentValue(WeatherMetric::Humidity);
        $apparentTemperature = $data->currentDetails['apparent_temperature'] ?? null;
        $uv = $data->currentDetails['uv_index'] ?? null;
        $wind = $data->currentValue(WeatherMetric::WindSpeed);

        if (($temperature !== null && $humidity !== null && $temperature >= 32 && $humidity >= 70)
            || ($apparentTemperature !== null && $apparentTemperature >= 36)) {
            $heatValue = $apparentTemperature ?? $temperature;
            $recommendations[] = $this->recommendation(
                code: 'heat_humidity',
                priority: $heatValue !== null && $heatValue >= 40
                    ? RecommendationPriority::Critical
                    : RecommendationPriority::Action,
                title: __('weather.recommendations.heat.title'),
                message: __('weather.recommendations.heat.message', [
                    'temperature' => $this->format(WeatherMetric::Temperature, $heatValue),
                    'humidity' => $humidity === null ? '—' : $this->formatNumber($humidity, 0),
                ]),
                metric: WeatherMetric::Temperature->value,
                value: $heatValue,
                unit: $this->units->unit(WeatherMetric::Temperature),
                validUntil: $reference->addHours(2),
            );
        }

        if ($uv !== null && $uv >= 6) {
            $recommendations[] = $this->recommendation(
                code: 'uv_high',
                priority: $uv >= 8 ? RecommendationPriority::Critical : RecommendationPriority::Action,
                title: __('weather.recommendations.uv.title'),
                message: __('weather.recommendations.uv.message', ['uv' => $this->formatNumber($uv)]),
                metric: 'uv_index',
                value: $uv,
                unit: null,
                validUntil: $reference->addHours(3),
            );
        }

        $rainPoint = $this->nextRainPoint($data, $reference);
        if ($rainPoint !== null) {
            $hours = max(1, $reference->diffInHours($rainPoint->at));
            $recommendations[] = $this->recommendation(
                code: 'rain_soon',
                priority: $hours <= 1 ? RecommendationPriority::Critical : RecommendationPriority::Action,
                title: __('weather.recommendations.rain.title'),
                message: __('weather.recommendations.rain.message', ['hours' => $hours]),
                metric: WeatherMetric::Precipitation->value,
                value: $rainPoint->value(WeatherMetric::Precipitation),
                unit: $this->units->unit(WeatherMetric::Precipitation),
                validUntil: $rainPoint->at,
            );
        }

        if ($wind !== null && $wind >= 35) {
            $recommendations[] = $this->recommendation(
                code: 'strong_wind',
                priority: $wind >= 50 ? RecommendationPriority::Critical : RecommendationPriority::Action,
                title: __('weather.recommendations.wind.title'),
                message: __('weather.recommendations.wind.message', ['wind' => $this->format(WeatherMetric::WindSpeed, $wind)]),
                metric: WeatherMetric::WindSpeed->value,
                value: $wind,
                unit: $this->units->unit(WeatherMetric::WindSpeed),
                validUntil: $reference->addHours(2),
            );
        }

        $change = $this->temperatureChange($data, $reference);
        if ($change !== null && abs($change['delta']) >= 6) {
            $rising = $change['delta'] > 0;
            $recommendations[] = $this->recommendation(
                code: $rising ? 'rapid_temperature_rise' : 'rapid_temperature_drop',
                priority: abs($change['delta']) >= 10 ? RecommendationPriority::Critical : RecommendationPriority::Action,
                title: $rising
                    ? __('weather.recommendations.temperature_rise.title')
                    : __('weather.recommendations.temperature_drop.title'),
                message: __($rising
                    ? 'weather.recommendations.temperature_rise.message'
                    : 'weather.recommendations.temperature_drop.message', [
                        'change' => $this->format(WeatherMetric::Temperature, abs($change['delta'])),
                    ]),
                metric: WeatherMetric::Temperature->value,
                value: $change['delta'],
                unit: $this->units->unit(WeatherMetric::Temperature),
                validUntil: $change['point']->at,
            );
        }

        usort($recommendations, fn (WeatherRecommendation $left, WeatherRecommendation $right): int => $right->priority->rank() <=> $left->priority->rank());

        return $recommendations;
    }

    /** @return list<array<string, mixed>> */
    public function notificationRules(): array
    {
        return [
            [
                'code' => 'heat_humidity',
                'priority' => RecommendationPriority::Action->value,
                'title' => __('weather.recommendations.heat.title'),
                'message' => __('weather.recommendations.heat.notification'),
                'temperature' => 32.0,
                'humidity' => 70.0,
                'apparent_temperature' => 36.0,
            ],
            [
                'code' => 'uv_high',
                'priority' => RecommendationPriority::Action->value,
                'title' => __('weather.recommendations.uv.title'),
                'message' => __('weather.recommendations.uv.notification'),
                'uv' => 6.0,
            ],
            [
                'code' => 'rain_soon',
                'priority' => RecommendationPriority::Action->value,
                'title' => __('weather.recommendations.rain.title'),
                'message' => __('weather.recommendations.rain.notification'),
                'precipitation' => 0.5,
            ],
            [
                'code' => 'strong_wind',
                'priority' => RecommendationPriority::Action->value,
                'title' => __('weather.recommendations.wind.title'),
                'message' => __('weather.recommendations.wind.notification'),
                'wind' => 35.0,
            ],
            [
                'code' => 'rapid_temperature_change',
                'priority' => RecommendationPriority::Action->value,
                'title' => __('weather.recommendations.temperature_change.title'),
                'message' => __('weather.recommendations.temperature_change.notification'),
                'change' => 6.0,
            ],
        ];
    }

    private function recommendation(
        string $code,
        RecommendationPriority $priority,
        string $title,
        string $message,
        ?string $metric,
        ?float $value,
        ?string $unit,
        CarbonImmutable $validUntil,
    ): WeatherRecommendation {
        return new WeatherRecommendation($code, $priority, $title, $message, $metric, $value, $unit, $validUntil);
    }

    private function nextRainPoint(WeatherData $data, CarbonImmutable $reference): ?WeatherPoint
    {
        foreach ($data->hourly as $point) {
            $hours = $reference->diffInHours($point->at, false);
            if ($hours < 0 || $hours > 2) {
                continue;
            }

            if (($point->value(WeatherMetric::Precipitation) ?? 0) >= 0.5) {
                return $point;
            }
        }

        return null;
    }

    /** @return array{delta: float, point: WeatherPoint}|null */
    private function temperatureChange(WeatherData $data, CarbonImmutable $reference): ?array
    {
        $future = array_values(array_filter(
            $data->hourly,
            fn (WeatherPoint $point): bool => $point->at->greaterThanOrEqualTo($reference)
                && $point->at->lessThanOrEqualTo($reference->addHours(6))
                && $point->value(WeatherMetric::Temperature) !== null,
        ));

        if (count($future) < 2) {
            return null;
        }

        $delta = $future[array_key_last($future)]->value(WeatherMetric::Temperature)
            - $future[0]->value(WeatherMetric::Temperature);

        return ['delta' => $delta, 'point' => $future[array_key_last($future)]];
    }

    private function format(WeatherMetric $metric, ?float $value): string
    {
        return $this->units->format($metric, $value, $metric === WeatherMetric::Precipitation ? 1 : 0).' '.$this->units->unit($metric);
    }

    private function formatNumber(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
