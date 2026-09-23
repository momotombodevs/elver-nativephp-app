<?php

namespace Database\Factories;

use App\Domain\AgroClima\Enums\ThresholdOperator;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\ClimateAlert;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClimateAlert>
 */
class ClimateAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'metric' => WeatherMetric::Temperature,
            'operator' => ThresholdOperator::Above,
            'threshold' => 35,
            'enabled' => true,
            'last_state' => null,
            'last_evaluated_at' => null,
            'last_triggered_at' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
