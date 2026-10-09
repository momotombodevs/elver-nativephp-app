<?php

namespace App\Domain\Weather\Data;

use App\Domain\Weather\Enums\RecommendationPriority;
use Carbon\CarbonImmutable;

final readonly class WeatherRecommendation
{
    public function __construct(
        public string $code,
        public RecommendationPriority $priority,
        public string $title,
        public string $message,
        public ?string $metric,
        public ?float $value,
        public ?string $unit,
        public CarbonImmutable $validUntil,
    ) {}

    /** @return array{code: string, priority: string, title: string, message: string, metric: string|null, value: float|null, unit: string|null, valid_until: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'priority' => $this->priority->value,
            'title' => $this->title,
            'message' => $this->message,
            'metric' => $this->metric,
            'value' => $this->value,
            'unit' => $this->unit,
            'valid_until' => $this->validUntil->toIso8601String(),
        ];
    }
}
