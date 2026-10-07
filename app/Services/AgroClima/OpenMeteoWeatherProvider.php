<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Data\DailyForecast;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Domain\AgroClima\Enums\WeatherMetric;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class OpenMeteoWeatherProvider implements WeatherProvider
{
    public function name(): string
    {
        return 'open-meteo';
    }

    public function fetch(Coordinates $coordinates): WeatherData
    {
        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry([100, 300], 0, fn (Throwable $exception): bool => $this->isTransient($exception))
            ->get($this->endpoint(), [
                'latitude' => $coordinates->latitude,
                'longitude' => $coordinates->longitude,
                'current' => $this->currentVariables(),
                'hourly' => $this->variables(),
                'daily' => 'temperature_2m_max,temperature_2m_min,uv_index_max',
                // Eight calendar days guarantee 168 future hourly points even
                // when the request happens late in the current day.
                'forecast_days' => 8,
                'timezone' => 'auto',
                'timeformat' => 'unixtime',
            ])
            ->throw();

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Open-Meteo returned an invalid payload.');
        }

        return $this->mapPayload($payload);
    }

    private function endpoint(): string
    {
        $configured = rtrim((string) config('services.open_meteo.url', 'https://api.open-meteo.com/v1/forecast'), '/');

        return str_ends_with($configured, '/v1/forecast')
            ? $configured
            : $configured.'/v1/forecast';
    }

    private function variables(): string
    {
        return implode(',', array_map(
            fn (WeatherMetric $metric): string => $metric->value,
            WeatherMetric::cases(),
        ));
    }

    private function currentVariables(): string
    {
        return $this->variables().',apparent_temperature,uv_index,wind_direction_10m';
    }

    /** @param array<string, mixed> $payload */
    private function mapPayload(array $payload): WeatherData
    {
        $timezone = $payload['timezone'] ?? null;
        $utcOffsetSeconds = $payload['utc_offset_seconds'] ?? null;
        $current = $payload['current'] ?? null;
        $hourly = $payload['hourly'] ?? null;

        if (! is_string($timezone) || ! is_array($current) || ! is_array($hourly)) {
            throw new InvalidArgumentException('Open-Meteo payload is missing required weather data.');
        }

        if (! is_int($utcOffsetSeconds)) {
            throw new InvalidArgumentException('Open-Meteo timezone offset must be an integer.');
        }

        $times = $hourly['time'] ?? null;
        if (! is_array($times) || ! array_is_list($times)) {
            throw new InvalidArgumentException('Open-Meteo hourly timestamps must be an ordered list.');
        }

        foreach (WeatherMetric::cases() as $metric) {
            $values = $hourly[$metric->value] ?? null;
            if (! is_array($values) || ! array_is_list($values) || count($values) !== count($times)) {
                throw new InvalidArgumentException("Open-Meteo hourly values for {$metric->value} are misaligned.");
            }
        }

        $hourlyPoints = [];
        foreach ($times as $index => $time) {
            $values = [];
            foreach (WeatherMetric::cases() as $metric) {
                $values[$metric->value] = $this->numberOrNull(
                    $hourly[$metric->value][$index],
                    "hourly {$metric->value} at index {$index}",
                );
            }

            $hourlyPoints[] = new WeatherPoint($this->timestamp($time, "hourly time at index {$index}"), $values);
        }

        $currentValues = [];
        foreach (WeatherMetric::cases() as $metric) {
            $currentValues[$metric->value] = $this->numberOrNull(
                $current[$metric->value] ?? null,
                "current {$metric->value}",
            );
        }

        $currentDetails = [];
        foreach ([
            'apparent_temperature',
            'uv_index',
            'wind_direction_10m',
        ] as $variable) {
            $currentDetails[$variable] = $this->numberOrNull(
                $current[$variable] ?? null,
                "current {$variable}",
            );
        }

        $dailyPayload = $payload['daily'] ?? null;
        if (! is_array($dailyPayload)) {
            throw new InvalidArgumentException('Open-Meteo daily forecast is missing.');
        }

        $dailyDates = $dailyPayload['time'] ?? null;
        if (! is_array($dailyDates) || ! array_is_list($dailyDates)) {
            throw new InvalidArgumentException('Open-Meteo daily timestamps must be an ordered list.');
        }

        $dailyValues = [];
        foreach (['temperature_2m_max', 'temperature_2m_min', 'uv_index_max'] as $variable) {
            $values = $dailyPayload[$variable] ?? null;
            if (! is_array($values) || ! array_is_list($values) || count($values) !== count($dailyDates)) {
                throw new InvalidArgumentException("Open-Meteo daily values for {$variable} are misaligned.");
            }

            $dailyValues[$variable] = $values;
        }

        $dailyForecast = [];
        foreach ($dailyDates as $index => $date) {
            if (! is_int($date)) {
                throw new InvalidArgumentException("Open-Meteo daily timestamp at index {$index} must be a Unix timestamp.");
            }

            $parsedDate = $this->timestamp($date, "daily time at index {$index}")
                ->addSeconds($utcOffsetSeconds)
                ->startOfDay();

            $dailyForecast[] = new DailyForecast(
                date: $parsedDate,
                maximumTemperature: $this->numberOrNull($dailyValues['temperature_2m_max'][$index], "daily temperature_2m_max at index {$index}"),
                minimumTemperature: $this->numberOrNull($dailyValues['temperature_2m_min'][$index], "daily temperature_2m_min at index {$index}"),
                maximumUvIndex: $this->numberOrNull($dailyValues['uv_index_max'][$index], "daily uv_index_max at index {$index}"),
            );
        }

        return new WeatherData(
            timezone: $timezone,
            current: new WeatherPoint(
                $this->timestamp($current['time'] ?? null, 'current time'),
                $currentValues,
            ),
            hourly: $hourlyPoints,
            currentDetails: $currentDetails,
            daily: $dailyForecast,
        );
    }

    private function timestamp(mixed $value, string $context): CarbonImmutable
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException("Open-Meteo {$context} must be a Unix timestamp.");
        }

        return CarbonImmutable::createFromTimestampUTC($value);
    }

    private function numberOrNull(mixed $value, string $context): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Open-Meteo {$context} must be numeric or null.");
        }

        $number = (float) $value;
        if (! is_finite($number)) {
            throw new InvalidArgumentException("Open-Meteo {$context} must be finite.");
        }

        return $number;
    }

    private function isTransient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->serverError() || $exception->response->status() === 429));
    }
}
