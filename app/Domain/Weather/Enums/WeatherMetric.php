<?php

namespace App\Domain\Weather\Enums;

enum WeatherMetric: string
{
    case Temperature = 'temperature_2m';
    case Humidity = 'relative_humidity_2m';
    case Precipitation = 'precipitation';
    case WindSpeed = 'wind_speed_10m';

    public function label(): string
    {
        return match ($this) {
            self::Temperature => __('weather.metrics.temperature'),
            self::Humidity => __('weather.metrics.humidity'),
            self::Precipitation => __('weather.metrics.precipitation'),
            self::WindSpeed => __('weather.metrics.wind'),
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
            self::Temperature => theme('primary'),
            self::Humidity => '#9B72D8',
            self::Precipitation => $this->accentColor(),
            self::WindSpeed => '#55308A',
        };
    }

    private function accentColor(): string
    {
        $color = theme('accent-foreground', '#6D28D9');

        return is_string($color) && $color !== '' ? $color : '#6D28D9';
    }
}
