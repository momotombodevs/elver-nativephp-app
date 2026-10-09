<?php

namespace App\Domain\Weather\Enums;

enum RecommendationPriority: string
{
    case Critical = 'critical';
    case Action = 'action';
    case Info = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Action => 2,
            self::Info => 1,
        };
    }
}
