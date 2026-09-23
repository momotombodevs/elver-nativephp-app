<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Data\Coordinates;
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
                'current' => $this->variables(),
                'hourly' => $this->variables(),
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

    /** @param array<string, mixed> $payload */
    private function mapPayload(array $payload): WeatherData
    {
        $timezone = $payload['timezone'] ?? null;
        $current = $payload['current'] ?? null;
        $hourly = $payload['hourly'] ?? null;

        if (! is_string($timezone) || ! is_array($current) || ! is_array($hourly)) {
            throw new InvalidArgumentException('Open-Meteo payload is missing required weather data.');
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

        return new WeatherData(
            timezone: $timezone,
            current: new WeatherPoint(
                $this->timestamp($current['time'] ?? null, 'current time'),
                $currentValues,
            ),
            hourly: $hourlyPoints,
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
