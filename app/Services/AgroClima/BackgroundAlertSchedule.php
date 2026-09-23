<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Enums\AlertState;
use App\Models\ClimateAlert;
use App\Models\Location;

final class BackgroundAlertSchedule
{
    public function sync(): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('ClimateNotifications.SyncSchedule')) {
            return;
        }

        $locations = Location::query()
            ->with(['climateAlerts' => fn ($query) => $query->where('enabled', true)])
            ->orderBy('id')
            ->get()
            ->map(function (Location $location): ?array {
                $alerts = $location->climateAlerts->map(
                    fn (ClimateAlert $alert): array => $this->alertPayload($location, $alert),
                )->values()->all();

                if ($alerts === []) {
                    return null;
                }

                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                    'alerts' => $alerts,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $configuredEndpoint = rtrim((string) config(
            'services.open_meteo.url',
            'https://api.open-meteo.com/v1/forecast',
        ), '/');
        $forecastUrl = str_ends_with($configuredEndpoint, '/v1/forecast')
            ? $configuredEndpoint
            : $configuredEndpoint.'/v1/forecast';

        nativephp_call('ClimateNotifications.SyncSchedule', json_encode([
            'forecastUrl' => $forecastUrl,
            'locations' => $locations,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function alertPayload(Location $location, ClimateAlert $alert): array
    {
        $signature = hash('sha256', json_encode([
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'metric' => $alert->metric->value,
            'operator' => $alert->operator->value,
            'threshold' => (float) $alert->threshold,
        ], JSON_THROW_ON_ERROR));

        return [
            'id' => $alert->id,
            'metric' => $alert->metric->value,
            'label' => $alert->metric->label(),
            'operator' => $alert->operator->value,
            'threshold' => (float) $alert->threshold,
            'unit' => $alert->metric->unit(),
            'signature' => $signature,
            'lastState' => $alert->last_state?->value,
            'lastEvaluatedAt' => $alert->last_state === AlertState::Stale || $alert->last_evaluated_at === null
                ? null
                : (int) $alert->last_evaluated_at->format('Uv'),
        ];
    }
}
