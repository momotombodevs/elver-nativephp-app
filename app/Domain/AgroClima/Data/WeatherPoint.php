<?php

namespace App\Domain\AgroClima\Data;

use App\Domain\AgroClima\Enums\WeatherMetric;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class WeatherPoint
{
    /**
     * @param  array<string, float|null>  $values
     */
    public function __construct(
        public CarbonImmutable $at,
        public array $values,
    ) {
        foreach (WeatherMetric::cases() as $metric) {
            if (! array_key_exists($metric->value, $values)) {
                throw new InvalidArgumentException("Missing weather value for {$metric->value}.");
            }

            $value = $values[$metric->value];
            if ($value !== null && ! is_finite($value)) {
                throw new InvalidArgumentException("Weather value for {$metric->value} must be finite.");
            }
        }
    }

    public function value(WeatherMetric $metric): ?float
    {
        return $this->values[$metric->value];
    }

    /** @return array{at: string, values: array<string, float|null>} */
    public function toArray(): array
    {
        return [
            'at' => $this->at->utc()->toIso8601String(),
            'values' => $this->values,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['at']) || ! is_string($payload['at'])) {
            throw new InvalidArgumentException('Weather point timestamp is missing.');
        }

        if (! isset($payload['values']) || ! is_array($payload['values'])) {
            throw new InvalidArgumentException('Weather point values are missing.');
        }

        $values = [];
        foreach (WeatherMetric::cases() as $metric) {
            $value = $payload['values'][$metric->value] ?? null;
            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("Weather value for {$metric->value} must be numeric or null.");
            }

            $values[$metric->value] = $value === null ? null : (float) $value;
        }

        try {
            $at = CarbonImmutable::parse($payload['at'])->utc();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Weather point timestamp is invalid.', previous: $exception);
        }

        return new self($at, $values);
    }
}
