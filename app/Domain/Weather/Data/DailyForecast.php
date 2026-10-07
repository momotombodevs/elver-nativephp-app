<?php

namespace App\Domain\Weather\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DailyForecast
{
    public function __construct(
        public CarbonImmutable $date,
        public ?float $maximumTemperature,
        public ?float $minimumTemperature,
        public ?float $maximumUvIndex,
    ) {
        foreach ([$maximumTemperature, $minimumTemperature, $maximumUvIndex] as $value) {
            if ($value !== null && ! is_finite($value)) {
                throw new InvalidArgumentException('Daily forecast values must be finite.');
            }
        }
    }

    /** @return array{date: string, maximum_temperature: float|null, minimum_temperature: float|null, maximum_uv_index: float|null} */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'maximum_temperature' => $this->maximumTemperature,
            'minimum_temperature' => $this->minimumTemperature,
            'maximum_uv_index' => $this->maximumUvIndex,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['date']) || ! is_string($payload['date'])) {
            throw new InvalidArgumentException('Daily forecast date is missing.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $payload['date']);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Daily forecast date is invalid.', previous: $exception);
        }

        if ($date === false) {
            throw new InvalidArgumentException('Daily forecast date is invalid.');
        }

        return new self(
            date: $date,
            maximumTemperature: self::numberOrNull($payload['maximum_temperature'] ?? null, 'maximum temperature'),
            minimumTemperature: self::numberOrNull($payload['minimum_temperature'] ?? null, 'minimum temperature'),
            maximumUvIndex: self::numberOrNull($payload['maximum_uv_index'] ?? null, 'maximum UV index'),
        );
    }

    private static function numberOrNull(mixed $value, string $label): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Daily forecast {$label} must be numeric or null.");
        }

        $number = (float) $value;
        if (! is_finite($number)) {
            throw new InvalidArgumentException("Daily forecast {$label} must be finite.");
        }

        return $number;
    }
}
