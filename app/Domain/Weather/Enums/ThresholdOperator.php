<?php

namespace App\Domain\Weather\Enums;

enum ThresholdOperator: string
{
    case Above = 'above';
    case Below = 'below';

    public function label(): string
    {
        return match ($this) {
            self::Above => __('weather.alerts.operator_above'),
            self::Below => __('weather.alerts.operator_below'),
        };
    }
}
