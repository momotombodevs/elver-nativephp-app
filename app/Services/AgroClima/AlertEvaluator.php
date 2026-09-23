<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Enums\AlertState;
use App\Domain\AgroClima\Enums\ThresholdOperator;
use App\Models\ClimateAlert;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class AlertEvaluator
{
    /** @return Collection<int, ClimateAlert> */
    public function evaluate(Location $location, WeatherData $data, bool $stale = false): Collection
    {
        $alerts = ClimateAlert::query()
            ->whereBelongsTo($location)
            ->where('enabled', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($alerts, $data, $stale): void {
            foreach ($alerts as $alert) {
                $previousState = $alert->last_state;
                $state = $this->stateFor($alert, $data, $stale);

                $alert->last_state = $state;
                $alert->last_evaluated_at = now();

                if ($state === AlertState::Exceeded && $previousState !== AlertState::Exceeded) {
                    $alert->last_triggered_at = now();
                }

                $alert->save();
            }
        });

        return $alerts->each->refresh();
    }

    public function stateFor(ClimateAlert $alert, WeatherData $data, bool $stale = false): AlertState
    {
        if ($stale) {
            return AlertState::Stale;
        }

        $value = $data->currentValue($alert->metric);
        if ($value === null) {
            return AlertState::NoData;
        }

        $exceeded = match ($alert->operator) {
            ThresholdOperator::Above => $value > (float) $alert->threshold,
            ThresholdOperator::Below => $value < (float) $alert->threshold,
        };

        return $exceeded ? AlertState::Exceeded : AlertState::Normal;
    }
}
