<?php

namespace App\Domain\AgroClima\Data;

use Carbon\CarbonImmutable;

final readonly class ForecastResult
{
    public function __construct(
        public WeatherData $data,
        public bool $stale,
        public CarbonImmutable $fetchedAt,
        public CarbonImmutable $expiresAt,
    ) {}
}
