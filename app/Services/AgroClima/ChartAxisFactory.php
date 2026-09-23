<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Enums\WeatherMetric;

final class ChartAxisFactory
{
    /**
     * @param  list<array<string, mixed>>  $series
     * @return array<string, bool|float|int|string>
     */
    public function yAxis(WeatherMetric $metric, array $series): array
    {
        $axis = [
            'title' => $metric->unit(),
            'valueFormat' => 'number',
            'maximumFractionDigits' => 1,
        ];

        if ($metric === WeatherMetric::Humidity) {
            return [...$axis, 'minimum' => 0.0, 'maximum' => 100.0, 'baseline' => 0.0, 'beginAtZero' => true];
        }

        $values = collect($series)
            ->flatMap(fn (array $item): array => $item['points'] ?? [])
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_int($value) || is_float($value))
            ->map(fn (int|float $value): float => (float) $value)
            ->values();

        if ($metric === WeatherMetric::Precipitation) {
            $maximum = max(1.0, (float) ($values->max() ?? 0.0));

            return [
                ...$axis,
                'minimum' => 0.0,
                'maximum' => $this->ceilTenth($maximum * 1.15),
                'baseline' => 0.0,
                'beginAtZero' => true,
            ];
        }

        if ($values->isEmpty()) {
            return [...$axis, 'beginAtZero' => false];
        }

        $minimum = (float) $values->min();
        $maximum = (float) $values->max();
        $minimumPadding = $metric === WeatherMetric::Temperature ? 1.0 : 2.0;
        $padding = max(($maximum - $minimum) * 0.12, $minimumPadding);

        return [
            ...$axis,
            'minimum' => $this->floorTenth($minimum - $padding),
            'maximum' => $this->ceilTenth($maximum + $padding),
            'beginAtZero' => false,
        ];
    }

    private function floorTenth(float $value): float
    {
        return floor($value * 10) / 10;
    }

    private function ceilTenth(float $value): float
    {
        return ceil($value * 10) / 10;
    }
}
