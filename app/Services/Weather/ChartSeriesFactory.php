<?php

namespace App\Services\Weather;

use App\Domain\Weather\Data\WeatherData;
use App\Domain\Weather\Data\WeatherPoint;
use App\Domain\Weather\Enums\WeatherMetric;
use App\Models\Location;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ChartSeriesFactory
{
    /**
     * @return list<array{id: string, name: string, color: string, points: list<array{id: string, label: string, value: float, x: string}>}>
     */
    public function forMetric(Location $location, WeatherData $data, WeatherMetric $metric, int $hours = 24): array
    {
        if ($hours < 1 || $hours > 168) {
            throw new InvalidArgumentException('Chart range must be between 1 and 168 hours.');
        }

        $startsAt = $data->current->at->startOfHour();
        $endsAt = $startsAt->addHours($hours);
        $seriesId = "location:{$location->getKey()}:metric:{$metric->value}";

        $points = array_values(array_map(
            function (WeatherPoint $point) use ($data, $metric, $seriesId): array {
                $local = $point->at->setTimezone($data->timezone);

                return [
                    'id' => "{$seriesId}:at:{$point->at->getTimestamp()}",
                    'label' => $local->format('d/m H:i'),
                    'value' => (float) $point->value($metric),
                    'x' => $point->at->utc()->toIso8601String(),
                ];
            },
            array_filter(
                $data->hourlyPoints($metric),
                fn (WeatherPoint $point): bool => $point->at->greaterThanOrEqualTo($startsAt)
                    && $point->at->lessThan($endsAt),
            ),
        ));

        return [[
            'id' => $seriesId,
            'name' => $metric->label(),
            'color' => $metric->color(),
            'points' => $points,
        ]];
    }

    /**
     * @return list<array{id: string, name: string, color: string, points: list<array{id: string, label: string, value: float, x: string}>}>
     */
    public function forMetricByDay(Location $location, WeatherData $data, WeatherMetric $metric, int $days = 7): array
    {
        if ($days < 1 || $days > 7) {
            throw new InvalidArgumentException('Chart range must be between 1 and 7 days.');
        }

        $localCurrent = $data->current->at->setTimezone($data->timezone);
        $startsAt = $localCurrent->startOfDay();
        $endsAt = $startsAt->addDays($days);
        $seriesId = "location:{$location->getKey()}:metric:{$metric->value}";
        $dailyValues = [];

        foreach ($data->hourlyPoints($metric) as $point) {
            if ($point->at->lessThan($startsAt) || $point->at->greaterThanOrEqualTo($endsAt)) {
                continue;
            }

            $date = $point->at->setTimezone($data->timezone)->toDateString();
            $dailyValues[$date][] = $point->value($metric);
        }

        ksort($dailyValues);

        $points = [];
        foreach ($dailyValues as $date => $values) {
            $total = array_sum($values);
            $value = $metric === WeatherMetric::Precipitation
                ? $total
                : $total / count($values);

            $points[] = [
                'id' => "{$seriesId}:date:{$date}",
                'label' => CarbonImmutable::createFromFormat('!Y-m-d', $date, $data->timezone)->format('d/m'),
                'value' => $value,
                'x' => $date,
            ];
        }

        return [[
            'id' => $seriesId,
            'name' => $metric->label(),
            'color' => $metric->color(),
            'points' => $points,
        ]];
    }
}
