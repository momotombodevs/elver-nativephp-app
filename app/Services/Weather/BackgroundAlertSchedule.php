<?php

namespace App\Services\Weather;

use App\Domain\Weather\Enums\AlertState;
use App\Models\AppSetting;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Services\Localization\LocalePreferences;

final class BackgroundAlertSchedule
{
    public function sync(): void
    {
        app(LocalePreferences::class)->apply();

        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('ClimateNotifications.SyncSchedule')) {
            return;
        }

        $notificationsEnabled = $this->settingEnabled('notifications_enabled', true);
        $locations = $notificationsEnabled ? Location::query()
            ->with(['climateAlerts' => fn ($query) => $query->where('enabled', true)])
            ->orderBy('id')
            ->get()
            ->map(function (Location $location): array {
                $alerts = $location->climateAlerts->map(
                    fn (ClimateAlert $alert): array => $this->alertPayload($location, $alert),
                )->values()->all();

                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                    'alerts' => $alerts,
                ];
            })
            ->values()
            ->all() : [];

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
            'locale' => app(LocalePreferences::class)->preference(),
            'notifications' => [
                'enabled' => $notificationsEnabled,
                'critical' => $this->settingEnabled('notification_critical', true),
                'recommendations' => $this->settingEnabled('notification_recommendations', true),
                'rapidChanges' => $this->settingEnabled('notification_rapid_changes', true),
                'dailySummary' => $this->settingEnabled('notification_daily_summary', false),
            ],
            'recommendationRules' => app(WeatherRecommendationService::class)->notificationRules(),
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
            'locationId' => (string) $location->getKey(),
            'metric' => $alert->metric->value,
            'label' => $alert->metric->label(),
            'operator' => $alert->operator->value,
            'threshold' => (float) $alert->threshold,
            'unit' => $alert->metric->unit(),
            'displayThreshold' => app(UnitPreferences::class)->format($alert->metric, (float) $alert->threshold),
            'displayUnit' => app(UnitPreferences::class)->unit($alert->metric),
            'signature' => $signature,
            'lastState' => $alert->last_state?->value,
            'lastEvaluatedAt' => $alert->last_state === AlertState::Stale || $alert->last_evaluated_at === null
                ? null
                : (int) $alert->last_evaluated_at->format('Uv'),
            'notificationTitle' => __('weather.alerts.critical_title'),
            'notificationMessage' => __('weather.alerts.threshold_message', [
                'label' => $alert->metric->label(),
                'operator' => $alert->operator->value === 'above'
                    ? __('weather.alerts.above')
                    : __('weather.alerts.below'),
                'threshold' => app(UnitPreferences::class)->format($alert->metric, (float) $alert->threshold),
                'unit' => app(UnitPreferences::class)->unit($alert->metric),
                'location' => $location->name,
            ]),
        ];
    }

    private function settingEnabled(string $key, bool $default): bool
    {
        $value = AppSetting::query()->whereKey($key)->value('value');

        return $value === null ? $default : $value !== '0';
    }
}
