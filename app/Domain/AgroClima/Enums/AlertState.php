<?php

namespace App\Domain\AgroClima\Enums;

enum AlertState: string
{
    case Normal = 'normal';
    case Exceeded = 'exceeded';
    case NoData = 'no_data';
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Exceeded => 'Umbral superado',
            self::NoData => 'Sin datos',
            self::Stale => 'Datos desactualizados',
        };
    }
}
