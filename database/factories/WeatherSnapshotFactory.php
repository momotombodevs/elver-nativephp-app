<?php

namespace Database\Factories;

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Data\WeatherPoint;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeatherSnapshot>
 */
class WeatherSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $observedAt = CarbonImmutable::now()->startOfHour();

        return [
            'location_id' => Location::factory(),
            'provider' => 'open-meteo',
            'schema_version' => 1,
            'payload' => (new WeatherData(
                timezone: 'America/Managua',
                current: new WeatherPoint($observedAt, [
                    'temperature_2m' => 28.5,
                    'relative_humidity_2m' => 72.0,
                    'precipitation' => 0.0,
                    'wind_speed_10m' => 9.5,
                ]),
                hourly: [
                    new WeatherPoint($observedAt, [
                        'temperature_2m' => 28.5,
                        'relative_humidity_2m' => 72.0,
                        'precipitation' => 0.0,
                        'wind_speed_10m' => 9.5,
                    ]),
                ],
            ))->toArray(),
            'fetched_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'fetched_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(30),
        ]);
    }
}
