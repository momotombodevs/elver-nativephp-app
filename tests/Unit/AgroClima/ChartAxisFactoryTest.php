<?php

use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Services\AgroClima\ChartAxisFactory;

it('frames temperature data around its observed range instead of zero', function () {
    $axis = app(ChartAxisFactory::class)->yAxis(WeatherMetric::Temperature, [[
        'points' => [
            ['value' => 25.8],
            ['value' => 33.2],
        ],
    ]]);

    expect($axis)
        ->toMatchArray([
            'title' => '°C',
            'beginAtZero' => false,
        ])
        ->and($axis['minimum'])->toBeGreaterThan(20.0)
        ->and($axis['maximum'])->toBeGreaterThan(33.2);
});

it('keeps bounded and zero-based metrics on meaningful domains', function () {
    $humidity = app(ChartAxisFactory::class)->yAxis(WeatherMetric::Humidity, []);
    $rain = app(ChartAxisFactory::class)->yAxis(WeatherMetric::Precipitation, [[
        'points' => [
            ['value' => 0.0],
            ['value' => 4.0],
        ],
    ]]);

    expect($humidity)->toMatchArray([
        'minimum' => 0.0,
        'maximum' => 100.0,
        'beginAtZero' => true,
    ])->and($rain)->toMatchArray([
        'minimum' => 0.0,
        'beginAtZero' => true,
    ])->and($rain['maximum'])->toBeGreaterThan(4.0);
});
