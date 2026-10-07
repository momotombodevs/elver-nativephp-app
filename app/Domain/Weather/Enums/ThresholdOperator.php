<?php

namespace App\Domain\Weather\Enums;

enum ThresholdOperator: string
{
    case Above = 'above';
    case Below = 'below';

    public function label(): string
    {
        return match ($this) {
            self::Above => 'Por encima de',
            self::Below => 'Por debajo de',
        };
    }
}
