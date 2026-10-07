<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Enums\WeatherMetric;
use Carbon\CarbonImmutable;

final class RainSummaryFactory
{
    /**
     * @return array{
     *     total: float|null,
     *     reportedHours: int,
     *     expectedHours: int,
     *     partial: bool,
     *     noData: bool,
     *     hasRain: bool,
     *     periods: list<string>
     * }
     */
    public function forNext24Hours(WeatherData $data): array
    {
        $expectedHours = 24;
        $startsAt = $data->current->at->startOfHour()->addHour();
        $endsAt = $startsAt->addHours($expectedHours);
        $hourlyPrecipitation = [];

        foreach ($data->hourly as $point) {
            if ($point->at->lessThan($startsAt) || $point->at->greaterThanOrEqualTo($endsAt)) {
                continue;
            }

            $hourlyPrecipitation[$point->at->getTimestamp()] = $point->value(WeatherMetric::Precipitation);
        }

        $hours = [];
        $reportedHours = 0;
        $total = 0.0;

        for ($offset = 0; $offset < $expectedHours; $offset++) {
            $timestamp = $startsAt->addHours($offset)->getTimestamp();
            $value = $hourlyPrecipitation[$timestamp] ?? null;

            if ($value !== null) {
                $reportedHours++;
                $total += max(0.0, $value);
            }

            $hours[] = ['timestamp' => $timestamp, 'value' => $value];
        }

        $periods = $this->rainPeriods($hours, $data->timezone);
        $noData = $reportedHours === 0;

        return [
            'total' => $noData ? null : $total,
            'reportedHours' => $reportedHours,
            'expectedHours' => $expectedHours,
            'partial' => $reportedHours < $expectedHours,
            'noData' => $noData,
            'hasRain' => $periods !== [],
            'periods' => $periods,
        ];
    }

    /**
     * @param  list<array{timestamp: int, value: float|null}>  $hours
     * @return list<string>
     */
    private function rainPeriods(array $hours, string $timezone): array
    {
        $periods = [];
        $periodStart = null;
        $periodEnd = null;

        foreach ($hours as $hour) {
            if ($hour['value'] !== null && $hour['value'] > 0.0) {
                $periodStart ??= $hour['timestamp'] - 3600;
                $periodEnd = $hour['timestamp'];

                continue;
            }

            if ($periodStart !== null && $periodEnd !== null) {
                $periods[] = $this->formatPeriod($periodStart, $periodEnd, $timezone);
                $periodStart = null;
                $periodEnd = null;
            }
        }

        if ($periodStart !== null && $periodEnd !== null) {
            $periods[] = $this->formatPeriod($periodStart, $periodEnd, $timezone);
        }

        return $periods;
    }

    private function formatPeriod(int $startsAt, int $endsAt, string $timezone): string
    {
        return CarbonImmutable::createFromTimestampUTC($startsAt)->setTimezone($timezone)->format('H:i')
            .'–'.CarbonImmutable::createFromTimestampUTC($endsAt)->setTimezone($timezone)->format('H:i');
    }
}
