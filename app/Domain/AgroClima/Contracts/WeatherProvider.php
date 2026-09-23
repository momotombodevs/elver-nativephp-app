<?php

namespace App\Domain\AgroClima\Contracts;

use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Data\WeatherData;

interface WeatherProvider
{
    public function name(): string;

    public function fetch(Coordinates $coordinates): WeatherData;
}
