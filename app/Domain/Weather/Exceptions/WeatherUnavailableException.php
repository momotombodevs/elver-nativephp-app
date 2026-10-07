<?php

namespace App\Domain\Weather\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class WeatherUnavailableException extends RuntimeException implements ShouldntReport {}
