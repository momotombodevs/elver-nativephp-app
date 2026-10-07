<?php

namespace App\Domain\Weather\Data;

use Carbon\CarbonImmutable;

final readonly class ForecastResult
{
    public function __construct(
        public WeatherData $data,
        public bool $stale,
        public CarbonImmutable $fetchedAt,
        public CarbonImmutable $expiresAt,
        public string $provider = 'open-meteo',
    ) {}
}
