<?php

namespace App\Domain\Weather\Data;

use App\Domain\Weather\Enums\WeatherMetric;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WeatherData
{
    /**
     * @param  list<WeatherPoint>  $hourly
     */
    public function __construct(
        public string $timezone,
        public WeatherPoint $current,
        public array $hourly,
        /** @var array<string, float|null> */
        public array $currentDetails = [],
        /** @var list<DailyForecast> */
        public array $daily = [],
    ) {
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Weather timezone is invalid.', previous: $exception);
        }

        foreach ($hourly as $point) {
            if (! $point instanceof WeatherPoint) {
                throw new InvalidArgumentException('Hourly weather data must contain only weather points.');
            }
        }

        foreach ($currentDetails as $key => $value) {
            if (! is_string($key) || ($value !== null && (! is_int($value) && ! is_float($value) || ! is_finite((float) $value)))) {
                throw new InvalidArgumentException('Current weather details must contain finite numeric values.');
            }
        }

        foreach ($daily as $forecast) {
            if (! $forecast instanceof DailyForecast) {
                throw new InvalidArgumentException('Daily forecast must contain only daily forecast values.');
            }
        }
    }

    public function currentValue(WeatherMetric $metric): ?float
    {
        return $this->current->value($metric);
    }

    /** @return list<WeatherPoint> */
    public function hourlyPoints(WeatherMetric $metric): array
    {
        return array_values(array_filter(
            $this->hourly,
            fn (WeatherPoint $point): bool => $point->value($metric) !== null,
        ));
    }

    /** @return array{timezone: string, current: array{at: string, values: array<string, float|null>}, hourly: list<array{at: string, values: array<string, float|null>}>, current_details: array<string, float|null>, daily: list<array{date: string, maximum_temperature: float|null, minimum_temperature: float|null, maximum_uv_index: float|null}>} */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'current' => $this->current->toArray(),
            'hourly' => array_map(
                fn (WeatherPoint $point): array => $point->toArray(),
                $this->hourly,
            ),
            'current_details' => $this->currentDetails,
            'daily' => array_map(
                fn (DailyForecast $forecast): array => $forecast->toArray(),
                $this->daily,
            ),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['timezone']) || ! is_string($payload['timezone'])) {
            throw new InvalidArgumentException('Weather timezone is missing.');
        }

        if (! isset($payload['current']) || ! is_array($payload['current'])) {
            throw new InvalidArgumentException('Current weather data is missing.');
        }

        if (! isset($payload['hourly']) || ! is_array($payload['hourly']) || ! array_is_list($payload['hourly'])) {
            throw new InvalidArgumentException('Hourly weather data must be an ordered list.');
        }

        $hourly = array_map(function (mixed $point): WeatherPoint {
            if (! is_array($point)) {
                throw new InvalidArgumentException('Hourly weather point must be an array.');
            }

            return WeatherPoint::fromArray($point);
        }, $payload['hourly']);

        $currentDetails = [];
        foreach (($payload['current_details'] ?? []) as $key => $value) {
            if (! is_string($key) || ($value !== null && ! is_int($value) && ! is_float($value))) {
                throw new InvalidArgumentException('Current weather details must be numeric or null.');
            }

            $currentDetails[$key] = $value === null ? null : (float) $value;
        }

        $daily = [];
        foreach (($payload['daily'] ?? []) as $forecast) {
            if (! is_array($forecast)) {
                throw new InvalidArgumentException('Daily forecast must be an ordered list.');
            }

            $daily[] = DailyForecast::fromArray($forecast);
        }

        usort(
            $hourly,
            fn (WeatherPoint $left, WeatherPoint $right): int => $left->at->getTimestamp() <=> $right->at->getTimestamp(),
        );

        return new self(
            timezone: $payload['timezone'],
            current: WeatherPoint::fromArray($payload['current']),
            hourly: $hourly,
            currentDetails: $currentDetails,
            daily: $daily,
        );
    }
}
