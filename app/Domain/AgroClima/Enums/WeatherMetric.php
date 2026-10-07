<?php

namespace App\Domain\AgroClima\Enums;

enum WeatherMetric: string
{
    case Temperature = 'temperature_2m';
    case Humidity = 'relative_humidity_2m';
    case Precipitation = 'precipitation';
    case WindSpeed = 'wind_speed_10m';

    public function label(): string
    {
        return match ($this) {
            self::Temperature => 'Temperatura',
            self::Humidity => 'Humedad relativa',
            self::Precipitation => 'Lluvia',
            self::WindSpeed => 'Velocidad del viento',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Temperature => '°C',
            self::Humidity => '%',
            self::Precipitation => 'mm',
            self::WindSpeed => 'km/h',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Temperature => '#7F00FF',
            self::Humidity => '#B86BFF',
            self::Precipitation => theme('accent'),
            self::WindSpeed => '#4B008F',
        };
    }
}
