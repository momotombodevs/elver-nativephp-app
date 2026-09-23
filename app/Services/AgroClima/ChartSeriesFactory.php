<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\Location;
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
}
