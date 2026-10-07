<?php

namespace App\Domain\Weather\Contracts;

use App\Domain\Weather\Data\Coordinates;
use App\Domain\Weather\Data\WeatherData;

interface WeatherProvider
{
    public function name(): string;

    public function fetch(Coordinates $coordinates): WeatherData;
}
