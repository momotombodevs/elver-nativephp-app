<?php

namespace App\Services\Weather;

use App\Domain\Weather\Enums\WeatherMetric;
use App\Models\AppSetting;

final class UnitPreferences
{
    private const SETTING_KEY = 'weather_units';

    public function preference(): string
    {
        return AppSetting::query()->whereKey(self::SETTING_KEY)->value('value') === 'imperial'
            ? 'imperial'
            : 'metric';
    }

    public function update(string $preference): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $preference === 'imperial' ? 'imperial' : 'metric'],
        );
    }

    public function isImperial(): bool
    {
        return $this->preference() === 'imperial';
    }

    public function unit(WeatherMetric $metric): string
    {
        if (! $this->isImperial()) {
            return $metric->unit();
        }

        return match ($metric) {
            WeatherMetric::Temperature => '°F',
            WeatherMetric::Precipitation => 'in',
            WeatherMetric::WindSpeed => 'mph',
            WeatherMetric::Humidity => '%',
        };
    }

    public function fromBase(WeatherMetric $metric, float $value): float
    {
        if (! $this->isImperial()) {
            return $value;
        }

        return match ($metric) {
            WeatherMetric::Temperature => ($value * 9 / 5) + 32,
            WeatherMetric::Precipitation => $value / 25.4,
            WeatherMetric::WindSpeed => $value * 0.621371,
            WeatherMetric::Humidity => $value,
        };
    }

    public function toBase(WeatherMetric $metric, float $value): float
    {
        if (! $this->isImperial()) {
            return $value;
        }

        return match ($metric) {
            WeatherMetric::Temperature => ($value - 32) * 5 / 9,
            WeatherMetric::Precipitation => $value * 25.4,
            WeatherMetric::WindSpeed => $value / 0.621371,
            WeatherMetric::Humidity => $value,
        };
    }

    /** @param list<array<string, mixed>> $series
     * @return list<array<string, mixed>>
     */
    public function series(WeatherMetric $metric, array $series): array
    {
        return array_map(function (array $item) use ($metric): array {
            $item['points'] = array_map(function (array $point) use ($metric): array {
                if (is_int($point['value'] ?? null) || is_float($point['value'] ?? null)) {
                    $point['value'] = $this->fromBase($metric, (float) $point['value']);
                }

                return $point;
            }, $item['points'] ?? []);

            return $item;
        }, $series);
    }

    public function format(WeatherMetric $metric, ?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format($this->fromBase($metric, $value), $decimals, ',', '.');
    }
}
