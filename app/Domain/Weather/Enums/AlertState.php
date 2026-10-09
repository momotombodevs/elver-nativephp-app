<?php

namespace App\Domain\Weather\Enums;

enum AlertState: string
{
    case Normal = 'normal';
    case Exceeded = 'exceeded';
    case NoData = 'no_data';
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::Normal => __('ui.alerts.state_normal'),
            self::Exceeded => __('ui.alerts.state_exceeded'),
            self::NoData => __('ui.alerts.state_no_data'),
            self::Stale => __('ui.alerts.state_stale'),
        };
    }
}
