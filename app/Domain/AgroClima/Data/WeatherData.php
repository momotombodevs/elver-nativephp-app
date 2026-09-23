<?php

namespace App\Domain\AgroClima\Data;

use App\Domain\AgroClima\Enums\WeatherMetric;
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

    /** @return array{timezone: string, current: array{at: string, values: array<string, float|null>}, hourly: list<array{at: string, values: array<string, float|null>}>} */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'current' => $this->current->toArray(),
            'hourly' => array_map(
                fn (WeatherPoint $point): array => $point->toArray(),
                $this->hourly,
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

        usort(
            $hourly,
            fn (WeatherPoint $left, WeatherPoint $right): int => $left->at->getTimestamp() <=> $right->at->getTimestamp(),
        );

        return new self(
            timezone: $payload['timezone'],
            current: WeatherPoint::fromArray($payload['current']),
            hourly: $hourly,
        );
    }
}
